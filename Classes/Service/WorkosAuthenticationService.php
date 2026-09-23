<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\AuthenticatedSession;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use Webconsulting\WorkosAuth\Security\AccessTokenClaims;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\StateService;
use WorkOS\Exception\ApiException;
use WorkOS\PKCEHelper;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuthenticateResponse;
use WorkOS\Resource\RadarStandaloneAssessRequestAction;
use WorkOS\Resource\User;
use WorkOS\Resource\UserCreateResponse;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\Service\PasswordPlaintext;
use WorkOS\Service\UserManagement;

/**
 * WorkOS AuthKit / User Management calls used by the login flows: hosted
 * authorization URLs, the OAuth callback, password / magic-auth / email
 * verification authentication and sign-up.
 *
 * The hosted flow uses PKCE on top of the client secret (RFC 9700): the
 * verifier stays in the server-side state of the login attempt, so an
 * authorization code intercepted on its way back is useless on its own.
 */
final readonly class WorkosAuthenticationService
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosClientFactory $workosClientFactory,
        private StateService $stateService,
    ) {}

    /**
     * @return array{url: string, cookie: Cookie|null}
     */
    public function buildFrontendAuthorizationUrl(
        ServerRequestInterface $request,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?SocialProvider $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): array {
        $site = $request->getAttribute('site');
        $sitePath = $site instanceof Site ? $site->getBase()->getPath() : '';

        return $this->buildAuthorizationUrl(
            request: $request,
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($sitePath, $this->configuration->getFrontendCallbackPath())
            ),
            cookiePath: $sitePath,
            context: LoginContext::Frontend,
            returnTo: $returnTo,
            screenHint: $screenHint,
            provider: $provider,
            loginHint: $loginHint,
            organizationId: $organizationId,
        );
    }

    /**
     * @return array{url: string, cookie: Cookie|null}
     */
    public function buildBackendAuthorizationUrl(
        ServerRequestInterface $request,
        string $backendBasePath,
        string $returnTo,
        ?string $loginHint = null,
        ?SocialProvider $provider = null,
        ?string $organizationId = null,
    ): array {
        return $this->buildAuthorizationUrl(
            request: $request,
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendCallbackPath())
            ),
            cookiePath: $backendBasePath,
            context: LoginContext::Backend,
            returnTo: $returnTo,
            provider: $provider,
            loginHint: $loginHint,
            organizationId: $organizationId,
        );
    }

    /**
     * Exchange the OAuth callback code for the WorkOS session and consume the
     * single-use state token issued by buildAuthorizationUrl().
     *
     * @return array{session: AuthenticatedSession, returnTo: string}
     */
    public function handleCallback(ServerRequestInterface $request, LoginContext $expectedContext): array
    {
        $queryParameters = $request->getQueryParams();
        $code = trim(MixedCaster::string($queryParameters['code'] ?? null));
        if ($code === '') {
            throw new \RuntimeException('The WorkOS callback did not contain an authorization code.', 1744277801);
        }

        $stateToken = $this->stateService->extractTokenFromCallbackState(MixedCaster::string($queryParameters['state'] ?? null));
        $payload = $this->stateService->consume($request, $expectedContext->value, $stateToken);

        $codeVerifier = MixedCaster::string($payload['codeVerifier'] ?? null);
        $userManagement = $this->userManagement();
        $response = $userManagement->authenticateWithCode(
            code: $code,
            // Login attempts started before PKCE was introduced carry no verifier.
            codeVerifier: $codeVerifier !== '' ? $codeVerifier : null,
            ipAddress: $this->getRemoteAddress($request),
            userAgent: $this->getUserAgent($request),
        );

        return [
            'session' => $this->toSession($userManagement, $response),
            'returnTo' => MixedCaster::string($payload['returnTo'] ?? null, '/'),
        ];
    }

    public function authenticateWithPassword(ServerRequestInterface $request, string $email, string $password): AuthenticatedSession
    {
        $userManagement = $this->userManagement();
        try {
            $response = $userManagement->authenticateWithPassword(
                email: $email,
                password: $password,
                ipAddress: $this->getRemoteAddress($request),
                userAgent: $this->getUserAgent($request),
            );
        } catch (ApiException $exception) {
            throw $this->toEmailVerificationException($exception, $email) ?? $exception;
        }

        return $this->toSession($userManagement, $response);
    }

    /**
     * Complete an authentication that previously failed with
     * `email_verification_required` by submitting the emailed code.
     */
    public function authenticateWithEmailVerification(ServerRequestInterface $request, string $code, string $pendingAuthenticationToken): AuthenticatedSession
    {
        $userManagement = $this->userManagement();
        $response = $userManagement->authenticateWithEmailVerification(
            code: $code,
            pendingAuthenticationToken: $pendingAuthenticationToken,
            ipAddress: $this->getRemoteAddress($request),
            userAgent: $this->getUserAgent($request),
        );

        return $this->toSession($userManagement, $response);
    }

    public function resendEmailVerification(string $userId): void
    {
        if ($userId === '') {
            throw new \RuntimeException('A WorkOS user id is required to resend the verification email.', 1744277813);
        }
        $this->userManagement()->sendVerificationEmail($userId);
    }

    /**
     * Email a one-time magic auth code to the address.
     */
    public function sendMagicAuthCode(string $email): void
    {
        $this->userManagement()->createMagicAuth($email);
    }

    public function authenticateWithMagicAuth(ServerRequestInterface $request, string $code, string $email): AuthenticatedSession
    {
        $userManagement = $this->userManagement();
        try {
            $response = $userManagement->authenticateWithMagicAuth(
                code: $code,
                email: $email,
                ipAddress: $this->getRemoteAddress($request),
                userAgent: $this->getUserAgent($request),
            );
        } catch (ApiException $exception) {
            throw $this->toEmailVerificationException($exception, $email) ?? $exception;
        }

        return $this->toSession($userManagement, $response);
    }

    /**
     * Ends a WorkOS session, so the hosted login asks for credentials again.
     */
    public function revokeSession(string $sessionId): void
    {
        // Runs while a user logs out: a slow API must not hold that up.
        $this->userManagement()->revokeSession($sessionId, new RequestOptions(timeout: 5, maxRetries: 0));
    }

    public function createUser(string $email, string $password, string $firstName = '', string $lastName = ''): UserCreateResponse
    {
        return $this->userManagement()->createUser(
            email: $email,
            password: $password !== '' ? new PasswordPlaintext($password) : null,
            firstName: self::nullIfEmpty($firstName),
            lastName: self::nullIfEmpty($lastName),
        );
    }

    /**
     * @return array{url: string, cookie: Cookie|null}
     */
    private function buildAuthorizationUrl(
        ServerRequestInterface $request,
        string $callbackUrl,
        string $cookiePath,
        LoginContext $context,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?SocialProvider $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): array {
        $userManagement = $this->userManagement();
        $pkce = PKCEHelper::generate();
        $issuedState = $this->stateService->issue($request, $context->value, $cookiePath, [
            'returnTo' => $returnTo,
            'codeVerifier' => $pkce['code_verifier'],
        ]);

        // WorkOS takes exactly one connection selector: a social provider the
        // editor picked, else the configured SSO connection, else AuthKit
        // (which may be pinned to an organization).
        $connectionId = $provider === null ? $this->configuration->getAuthkitConnectionId() : null;
        $sdkProvider = match (true) {
            $provider !== null => $provider->toSdk(),
            $connectionId !== null => null,
            default => UserManagementAuthenticationProvider::Authkit,
        };

        return [
            'url' => $userManagement->getAuthorizationUrl(
                redirectUri: $callbackUrl,
                codeChallengeMethod: $pkce['code_challenge_method'],
                codeChallenge: $pkce['code_challenge'],
                domainHint: $this->configuration->getAuthkitDomainHint(),
                connectionId: $connectionId,
                // Only the hosted AuthKit screen knows sign-in and sign-up screens.
                screenHint: $sdkProvider === UserManagementAuthenticationProvider::Authkit
                    ? RadarStandaloneAssessRequestAction::tryFrom($screenHint) ?? RadarStandaloneAssessRequestAction::SignIn
                    : null,
                loginHint: $loginHint,
                provider: $sdkProvider,
                state: json_encode(['token' => $issuedState['token']], JSON_THROW_ON_ERROR),
                organizationId: $sdkProvider === UserManagementAuthenticationProvider::Authkit
                    ? self::nullIfEmpty($organizationId ?? '') ?? $this->configuration->getAuthkitOrganizationId()
                    : null,
            ),
            'cookie' => $issuedState['cookie'],
        ];
    }

    /**
     * WorkOS reports `email_verification_required` as an API error whose
     * decoded body carries the pending authentication token needed to finish
     * the login with an emailed code.
     */
    private function toEmailVerificationException(ApiException $exception, string $email): ?EmailVerificationRequiredException
    {
        if ($exception->errorCode !== 'email_verification_required'
            && !str_contains($exception->getMessage(), 'email_verification_required')
        ) {
            return null;
        }

        $body = $exception->rawBody ?? [];
        $pendingToken = MixedCaster::string($body['pending_authentication_token'] ?? null);
        if ($pendingToken === '') {
            return null;
        }

        return new EmailVerificationRequiredException(
            pendingAuthenticationToken: $pendingToken,
            email: MixedCaster::string($body['email'] ?? null, $email),
            userId: MixedCaster::string($body['user_id'] ?? null),
        );
    }

    private function userManagement(): UserManagement
    {
        return $this->workosClientFactory->client()->userManagement();
    }

    private function toSession(UserManagement $userManagement, AuthenticateResponse $response): AuthenticatedSession
    {
        return AuthenticatedSession::fromResponse(
            $response,
            $this->enrichUser($userManagement, $response->user),
            AccessTokenClaims::sessionId($response->accessToken),
        );
    }

    /**
     * The authentication response user lacks custom metadata; a follow-up
     * getUser() call delivers the complete profile when it succeeds.
     */
    private function enrichUser(UserManagement $userManagement, User $user): User
    {
        try {
            return $userManagement->getUser($user->id);
        } catch (\Throwable) {
            return $user;
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

    private static function nullIfEmpty(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
