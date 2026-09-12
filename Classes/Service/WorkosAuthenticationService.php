<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\StateService;
use WorkOS\Exception\ApiException;
use WorkOS\Resource\RadarStandaloneAssessRequestAction;
use WorkOS\Resource\User;
use WorkOS\Resource\UserCreateResponse;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\Service\PasswordPlaintext;
use WorkOS\Service\UserManagement;

final readonly class WorkosAuthenticationService
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
        $code = trim(MixedCaster::string($queryParameters['code'] ?? null));
        if ($code === '') {
            throw new \RuntimeException('The WorkOS callback did not contain an authorization code.', 1744277801);
        }

        $stateToken = $this->stateService->extractTokenFromCallbackState(MixedCaster::string($queryParameters['state'] ?? null));
        $payload = $this->stateService->consume($request, $expectedContext, $stateToken);

        $userManagement = $this->workosClientFactory->createUserManagement();
        $authenticationResponse = $userManagement->authenticateWithCode(
            code: $code,
            ipAddress: $this->getRemoteAddress($request),
            userAgent: $this->getUserAgent($request),
        );

        return [
            'workosUser' => $this->enrichUser($userManagement, $authenticationResponse->user),
            'returnTo' => MixedCaster::string($payload['returnTo'] ?? null, '/'),
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

    /**
     * Only the social providers the login templates offer are forwarded;
     * everything else falls back to the hosted AuthKit screen.
     */
    private function resolveProvider(?string $provider): UserManagementAuthenticationProvider
    {
        return $provider !== null && in_array($provider, WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS, true)
            ? UserManagementAuthenticationProvider::from($provider)
            : UserManagementAuthenticationProvider::Authkit;
    }

    /**
     * workos-php 7.0 replaced the dedicated screen-hint enum with the shared
     * `RadarStandaloneAssessRequestAction` (`sign-in` / `sign-up`).
     */
    private function resolveScreenHint(string $screenHint): RadarStandaloneAssessRequestAction
    {
        return RadarStandaloneAssessRequestAction::tryFrom($screenHint) ?? RadarStandaloneAssessRequestAction::SignIn;
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
     * Inspect a WorkOS API exception and, if it is an
     * `email_verification_required` error, re-throw a typed
     * EmailVerificationRequiredException carrying the handshake data.
     *
     * The SDK exposes the full decoded error body via `ApiException::$rawBody`;
     * the pending token lives there, not in the human-readable message.
     */
    private function rethrowEmailVerificationException(\Throwable $exception, string $email): void
    {
        if (!$exception instanceof ApiException) {
            return;
        }

        $message = $exception->getMessage();
        if ($exception->errorCode !== 'email_verification_required'
            && !str_contains($message, 'email_verification_required')
            && !str_contains($message, 'Email ownership must be verified')
        ) {
            return;
        }

        $body = $exception->rawBody ?? [];
        $pendingToken = MixedCaster::string($body['pending_authentication_token'] ?? null);
        if ($pendingToken === '') {
            return;
        }

        throw new EmailVerificationRequiredException(
            pendingAuthenticationToken: $pendingToken,
            email: MixedCaster::string($body['email'] ?? null, $email),
            emailVerificationId: MixedCaster::string($body['email_verification_id'] ?? null),
            userId: MixedCaster::string($body['user_id'] ?? $body['userId'] ?? null),
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

    /**
     * workos-php 8.0 returns a dedicated `UserCreateResponse` (same shape as
     * `User` plus `radarAuthAttemptId`) from the create endpoint.
     */
    public function createUser(string $email, string $password, string $firstName = '', string $lastName = ''): UserCreateResponse
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();

        return $userManagement->createUser(
            email: $email,
            password: $password !== '' ? new PasswordPlaintext($password) : null,
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
        return $normalizedParams instanceof NormalizedParams
            ? self::nullIfEmpty($normalizedParams->getRemoteAddress())
            : null;
    }

    private function getUserAgent(ServerRequestInterface $request): ?string
    {
        return self::nullIfEmpty(trim($request->getHeaderLine('User-Agent')));
    }
}
