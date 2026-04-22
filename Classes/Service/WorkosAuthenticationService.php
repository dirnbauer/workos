<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use WebConsulting\WorkosAuth\Security\StateService;
use WorkOS\Resource\User;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\Resource\UserManagementAuthenticationScreenHint;
use WorkOS\Service\UserManagement;

final class WorkosAuthenticationService
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosClientFactory $workosClientFactory,
        private StateService $stateService,
    ) {}

    /**
     * @return array{url:string,cookie:Cookie|null}
     */
    public function buildFrontendAuthorizationUrl(
        ServerRequestInterface $request,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?string $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): array {
        $site = $request->getAttribute('site');
        $sitePath = $site instanceof Site ? $site->getBase()->getPath() : '';
        $callbackPath = PathUtility::getPathRelativeToSiteBase(
            PathUtility::joinBaseAndPath($sitePath, $this->configuration->getFrontendCallbackPath()),
            '/'
        );

        return $this->buildAuthorizationUrl(
            request: $request,
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest($request, $callbackPath),
            cookiePath: $sitePath,
            context: 'frontend',
            returnTo: $returnTo,
            screenHint: $screenHint,
            provider: $provider,
            loginHint: $loginHint,
            organizationId: $organizationId,
        );
    }

    /**
     * @return array{url:string,cookie:Cookie|null}
     */
    public function buildBackendAuthorizationUrl(
        ServerRequestInterface $request,
        string $backendBasePath,
        string $returnTo,
        ?string $loginHint = null,
        ?string $provider = null,
        ?string $organizationId = null,
    ): array {
        return $this->buildAuthorizationUrl(
            request: $request,
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendCallbackPath())
            ),
            cookiePath: $backendBasePath,
            context: 'backend',
            returnTo: $returnTo,
            provider: $provider,
            loginHint: $loginHint,
            organizationId: $organizationId,
        );
    }

    /**
     * @return array{workosUser: User, returnTo: string}
     */
    public function handleCallback(ServerRequestInterface $request, string $expectedContext): array
    {
        $queryParameters = $request->getQueryParams();
        $code = trim(self::stringFromMixed($queryParameters['code'] ?? null));
        if ($code === '') {
            throw new \RuntimeException('The WorkOS callback did not contain an authorization code.', 1744277801);
        }

        $stateToken = $this->stateService->extractTokenFromCallbackState(self::stringFromMixed($queryParameters['state'] ?? null));
        $payload = $this->stateService->consume($request, $expectedContext, $stateToken);

        $userManagement = $this->workosClientFactory->createUserManagement();
        $authenticationResponse = $userManagement->authenticateWithCode(
            code: $code,
            ipAddress: $this->getRemoteAddress($request),
            userAgent: $this->getUserAgent($request),
        );

        return [
            'workosUser' => $this->enrichUser($userManagement, $authenticationResponse->user),
            'returnTo' => self::stringFromMixed($payload['returnTo'] ?? null, '/'),
        ];
    }

    /**
     * @return array{url:string,cookie:Cookie|null}
     */
    private function buildAuthorizationUrl(
        ServerRequestInterface $request,
        string $callbackUrl,
        string $cookiePath,
        string $context,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?string $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): array {
        $this->assertBaseConfiguration();

        $userManagement = $this->workosClientFactory->createUserManagement();
        $issuedState = $this->stateService->issue($request, $context, $cookiePath, [
            'context' => $context,
            'returnTo' => $returnTo,
        ]);

        $effectiveProvider = $this->resolveProvider($provider);
        $effectiveOrgId = $organizationId !== null && $organizationId !== ''
            ? $organizationId
            : self::nullIfEmpty($this->configuration->getAuthkitOrganizationId());

        return [
            'url' => $userManagement->getAuthorizationUrl(
                redirectUri: $callbackUrl,
                domainHint: self::nullIfEmpty($this->configuration->getAuthkitDomainHint()),
                connectionId: self::nullIfEmpty($this->configuration->getAuthkitConnectionId()),
                screenHint: $provider === null
                    ? $this->resolveScreenHint($screenHint)
                    : null,
                loginHint: $loginHint,
                provider: $effectiveProvider,
                state: json_encode(['token' => $issuedState['token']], JSON_THROW_ON_ERROR),
                organizationId: $effectiveOrgId,
            ),
            'cookie' => $issuedState['cookie'] instanceof Cookie ? $issuedState['cookie'] : null,
        ];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }

    private function resolveProvider(?string $provider): UserManagementAuthenticationProvider
    {
        return match ($provider) {
            'AppleOAuth' => UserManagementAuthenticationProvider::AppleOAuth,
            'GitHubOAuth' => UserManagementAuthenticationProvider::GitHubOAuth,
            'GoogleOAuth' => UserManagementAuthenticationProvider::GoogleOAuth,
            'MicrosoftOAuth' => UserManagementAuthenticationProvider::MicrosoftOAuth,
            default => UserManagementAuthenticationProvider::Authkit,
        };
    }

    private function resolveScreenHint(string $screenHint): UserManagementAuthenticationScreenHint
    {
        return $screenHint === UserManagementAuthenticationScreenHint::SignUp->value
            ? UserManagementAuthenticationScreenHint::SignUp
            : UserManagementAuthenticationScreenHint::SignIn;
    }

    /**
     * @return array{workosUser: User}
     */
    public function authenticateWithPassword(ServerRequestInterface $request, string $email, string $password): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();

        try {
            $response = $userManagement->authenticateWithPassword(
                email: $email,
                password: $password,
                ipAddress: $this->getRemoteAddress($request),
                userAgent: $this->getUserAgent($request),
            );
        } catch (\Throwable $exception) {
            $this->rethrowEmailVerificationException($exception, $email);
            throw $exception;
        }

        return ['workosUser' => $this->enrichUser($userManagement, $response->user)];
    }

    /**
     * Complete an authentication that previously failed with
     * `email_verification_required` by submitting the code the user
     * received via email.
     */
    /**
     * @return array{workosUser: User}
     */
    public function authenticateWithEmailVerification(ServerRequestInterface $request, string $code, string $pendingAuthenticationToken): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $response = $userManagement->authenticateWithEmailVerification(
            code: $code,
            pendingAuthenticationToken: $pendingAuthenticationToken,
            ipAddress: $this->getRemoteAddress($request),
            userAgent: $this->getUserAgent($request),
        );

        return ['workosUser' => $this->enrichUser($userManagement, $response->user)];
    }

    /**
     * Resend the verification email for a pending WorkOS user.
     */
    public function resendEmailVerification(string $userId): void
    {
        $this->assertBaseConfiguration();
        if ($userId === '') {
            throw new \RuntimeException('A WorkOS user id is required to resend the verification email.', 1744277813);
        }
        $userManagement = $this->workosClientFactory->createUserManagement();
        $userManagement->sendVerificationEmail($userId);
    }

    /**
     * Inspect a WorkOS exception and, if it is a
     * `email_verification_required` error, re-throw a typed
     * EmailVerificationRequiredException carrying the handshake data.
     */
    private function rethrowEmailVerificationException(\Throwable $exception, string $email): void
    {
        $message = $exception->getMessage();
        if (!str_contains($message, 'email_verification_required')
            && !str_contains($message, 'Email ownership must be verified')
        ) {
            return;
        }

        $pendingToken = '';
        $verificationId = '';
        $workosEmail = $email;
        $userId = '';

        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            $pendingToken = self::stringFromMixed($decoded['pending_authentication_token'] ?? null);
            $verificationId = self::stringFromMixed($decoded['email_verification_id'] ?? null);
            $workosEmail = self::stringFromMixed($decoded['email'] ?? $email, $email);
            $userId = self::stringFromMixed($decoded['user_id'] ?? $decoded['userId'] ?? null);
        }

        if ($pendingToken === '') {
            $responseBody = $this->extractResponseBodyJson($exception);
            if ($responseBody !== null) {
                $pendingToken = self::stringFromMixed($responseBody['pending_authentication_token'] ?? $pendingToken, $pendingToken);
                $verificationId = self::stringFromMixed($responseBody['email_verification_id'] ?? $verificationId, $verificationId);
                $workosEmail = self::stringFromMixed($responseBody['email'] ?? $workosEmail, $workosEmail);
                $userId = self::stringFromMixed($responseBody['user_id'] ?? $responseBody['userId'] ?? $userId, $userId);
            }
        }

        if ($pendingToken === '') {
            return;
        }

        throw new EmailVerificationRequiredException(
            pendingAuthenticationToken: $pendingToken,
            email: $workosEmail,
            emailVerificationId: $verificationId,
            userId: $userId,
        );
    }

    /**
     * @return array{magicAuthId: string, userId: string, email: string}
     */
    public function sendMagicAuthCode(string $email): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $magicAuth = $userManagement->createMagicAuth($email);

        return [
            'magicAuthId' => $magicAuth->id,
            'userId' => $magicAuth->userId,
            'email' => $email,
        ];
    }

    /**
     * @return array{workosUser: User}
     */
    public function authenticateWithMagicAuth(ServerRequestInterface $request, string $code, string $userId): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        try {
            $response = $userManagement->authenticateWithMagicAuth(
                code: $code,
                email: $userId,
                ipAddress: $this->getRemoteAddress($request),
                userAgent: $this->getUserAgent($request),
            );
        } catch (\Throwable $exception) {
            $this->rethrowEmailVerificationException($exception, '');
            throw $exception;
        }

        return ['workosUser' => $this->enrichUser($userManagement, $response->user)];
    }

    public function createUser(string $email, string $password, string $firstName = '', string $lastName = ''): User
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();

        return $userManagement->createUser(
            email: $email,
            password: self::nullIfEmpty($password),
            firstName: self::nullIfEmpty($firstName),
            lastName: self::nullIfEmpty($lastName),
        );
    }

    private function enrichUser(UserManagement $userManagement, User $user): User
    {
        try {
            return $userManagement->getUser($user->id);
        } catch (\Throwable) {
            return $user;
        }
    }

    private function assertBaseConfiguration(): void
    {
        if ($this->configuration->getApiKey() === '' || $this->configuration->getClientId() === '') {
            throw new \RuntimeException('WorkOS API key and client ID must be configured before login can be used.', 1744277804);
        }

        if (mb_strlen($this->configuration->getCookiePassword()) < 32) {
            throw new \RuntimeException('WorkOS cookie password must be at least 32 characters long.', 1744277805);
        }
    }

    private function getRemoteAddress(ServerRequestInterface $request): ?string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (is_object($normalizedParams) && method_exists($normalizedParams, 'getRemoteAddress')) {
            $value = $normalizedParams->getRemoteAddress();
            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }

    private function getUserAgent(ServerRequestInterface $request): ?string
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        return $userAgent !== '' ? $userAgent : null;
    }

    private static function stringFromMixed(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return $default;
    }

    /**
     * WorkOS SDK exceptions expose a dynamic `$response` object with a
     * `$body` string. Both are undeclared on the base `\Throwable`, so
     * narrow via closure-based property access.
     *
     * @return array<mixed>|null
     */
    private function extractResponseBodyJson(\Throwable $exception): ?array
    {
        if (!property_exists($exception, 'response')) {
            return null;
        }
        $response = (static fn(\Throwable $e) => $e->{'response'} ?? null)($exception);
        if (!is_object($response) || !property_exists($response, 'body')) {
            return null;
        }
        $body = (static fn(object $r) => $r->{'body'} ?? null)($response);
        if (!is_string($body) || $body === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
