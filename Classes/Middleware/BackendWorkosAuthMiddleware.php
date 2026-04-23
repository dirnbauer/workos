<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Middleware\RequestTokenMiddleware;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use WebConsulting\WorkosAuth\Security\MixedCaster;
use WebConsulting\WorkosAuth\Security\SecretRedactor;
use WebConsulting\WorkosAuth\Security\StateService;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\RequestBody;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class BackendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const EMAIL_VERIFICATION_CONTEXT = 'backend_email_verification';
    private const MAGIC_AUTH_CONTEXT = 'backend_magic_auth';

    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosAuthenticationService $workosAuthenticationService,
        private UserProvisioningService $userProvisioningService,
        private Typo3SessionService $typo3SessionService,
        private StateService $stateService,
        private Context $context,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestPath = PathUtility::normalizePath($request->getUri()->getPath());

        if ($this->pathMatches($requestPath, $this->configuration->getBackendLoginPath())) {
            return $this->handleLogin($request);
        }

        if ($this->pathMatches($requestPath, $this->configuration->getBackendCallbackPath())) {
            return $this->handleCallback($request);
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/password-auth') && $request->getMethod() === 'POST') {
            return $this->processWithBackendRequestToken(
                $request,
                fn(ServerRequestInterface $request): ResponseInterface => $this->handlePasswordAuth($request)
            );
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/magic-auth-send') && $request->getMethod() === 'POST') {
            return $this->processWithBackendRequestToken(
                $request,
                fn(ServerRequestInterface $request): ResponseInterface => $this->handleMagicAuthSend($request)
            );
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/magic-auth-verify') && $request->getMethod() === 'POST') {
            return $this->processWithBackendRequestToken(
                $request,
                fn(ServerRequestInterface $request): ResponseInterface => $this->handleMagicAuthVerify($request)
            );
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/email-verify') && $request->getMethod() === 'POST') {
            return $this->processWithBackendRequestToken(
                $request,
                fn(ServerRequestInterface $request): ResponseInterface => $this->handleEmailVerify($request)
            );
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/email-verify-resend') && $request->getMethod() === 'POST') {
            return $this->processWithBackendRequestToken(
                $request,
                fn(ServerRequestInterface $request): ResponseInterface => $this->handleEmailVerifyResend($request)
            );
        }

        return $handler->handle($request);
    }

    private function pathMatches(string $requestPath, string $configuredPath): bool
    {
        return $requestPath === $configuredPath || str_ends_with($requestPath, $configuredPath);
    }

    private function handleLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->configuration->isBackendEnabled()) {
            return $this->errorResponse($this->translate('error.backendLoginDisabled'), 503);
        }
        if (!$this->configuration->isBackendReady()) {
            return $this->errorResponse($this->translate('error.backendLoginNotSupported'), 503);
        }

        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            $this->configuration->getBackendLoginPath()
        );

        $queryParams = $request->getQueryParams();
        $fallbackReturnTo = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
        $returnTo = PathUtility::sanitizeReturnTo(
            $request,
            MixedCaster::string($queryParams['returnTo'] ?? null),
            $fallbackReturnTo
        );

        $requestedProvider = MixedCaster::string($queryParams['provider'] ?? null);
        $provider = in_array($requestedProvider, WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS, true)
            ? $requestedProvider
            : null;

        $loginHint = trim(MixedCaster::string($queryParams['login_hint'] ?? null));
        $organizationId = trim(MixedCaster::string($queryParams['organization'] ?? null));

        try {
            $authorizationRequest = $this->workosAuthenticationService->buildBackendAuthorizationUrl(
                $request,
                $backendBasePath,
                $returnTo,
                $loginHint !== '' ? $loginHint : null,
                $provider,
                $organizationId !== '' ? $organizationId : null,
            );

            return $this->appendCookie(
                new RedirectResponse((string)$authorizationRequest['url'], 302),
                $authorizationRequest['cookie'] ?? null,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS backend login error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translate('error.loginError'), 500);
        }
    }

    private function handleCallback(ServerRequestInterface $request): ResponseInterface
    {
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            $this->configuration->getBackendCallbackPath()
        );

        if (!$this->configuration->isBackendReady()) {
            return $this->redirectToLoginWithError(
                $backendBasePath,
                $this->translate('error.backendLoginNotSupported')
            );
        }

        try {
            $authenticationResult = $this->workosAuthenticationService->handleCallback($request, 'backend');
            $backendUser = $this->userProvisioningService->resolveBackendUser($authenticationResult['workosUser']);

            return $this->typo3SessionService->createBackendLoginResponse(
                $request,
                $backendUser,
                $authenticationResult['returnTo'],
                $authenticationResult['workosUser']->id ?? null,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS backend callback error: ' . SecretRedactor::redact($exception->getMessage()));
            $fallbackLoginPath = PathUtility::joinBaseAndPath($backendBasePath, '/login');
            return new RedirectResponse(
                PathUtility::appendQueryParameters($fallbackLoginPath, [
                    'loginProvider' => '1744276800',
                    'workosAuthError' => $this->sanitizeErrorMessage($exception->getMessage()),
                ]),
                303
            );
        }
    }

    private function handlePasswordAuth(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/password-auth'
        );

        if ($email === '' || $password === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.enterEmailAndPassword'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.csrfTokenInvalid'));
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithPassword($request, $email, $password);
            $backendUser = $this->userProvisioningService->resolveBackendUser($result['workosUser']);
            $successPath = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
            return $this->typo3SessionService->createBackendLoginResponse(
                $request,
                $backendUser,
                $successPath,
                $result['workosUser']->id ?? null,
            );
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend password auth error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function handleMagicAuthSend(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $email = $body->trimmedString('email');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/magic-auth-send'
        );

        if ($email === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.enterEmail'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.csrfTokenInvalid'));
        }

        try {
            $magicAuth = $this->workosAuthenticationService->sendMagicAuthCode($email);
            $issuedState = $this->stateService->issue(
                $request,
                self::MAGIC_AUTH_CONTEXT,
                $backendBasePath,
                [
                    'email' => $magicAuth['email'],
                ]
            );

            return $this->redirectToLogin(
                $backendBasePath,
                ['magicAuthState' => $issuedState['token']],
                $issuedState['cookie'] ?? null
            );
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend magic auth send error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function handleMagicAuthVerify(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $code = $body->trimmedString('code');
        $magicAuthState = $body->trimmedString('magicAuthState');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/magic-auth-verify'
        );

        if ($code === '' || $magicAuthState === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.invalidMagicAuthSession'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.csrfTokenInvalid'));
        }

        try {
            $magicAuthPayload = $this->consumeBackendState(
                $request,
                self::MAGIC_AUTH_CONTEXT,
                $magicAuthState
            );
            $email = MixedCaster::string($magicAuthPayload['email'] ?? null);
            if ($email === '') {
                return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.invalidMagicAuthSession'));
            }

            $result = $this->workosAuthenticationService->authenticateWithMagicAuth($request, $code, $email);
            $backendUser = $this->userProvisioningService->resolveBackendUser($result['workosUser']);
            $successPath = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
            return $this->typo3SessionService->createBackendLoginResponse(
                $request,
                $backendUser,
                $successPath,
                $result['workosUser']->id ?? null,
            );
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\RuntimeException) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.invalidMagicAuthSession'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend magic auth verify error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function handleEmailVerify(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $code = $body->trimmedString('code');
        $emailVerificationState = $body->trimmedString('emailVerificationState');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/email-verify'
        );

        if ($emailVerificationState === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->translate('error.csrfTokenInvalid'),
                ]
            );
        }
        if ($code === '') {
            return $this->redirectToLogin(
                $backendBasePath,
                ['emailVerificationState' => $emailVerificationState]
            );
        }

        try {
            $verificationPayload = $this->peekBackendState(
                $request,
                self::EMAIL_VERIFICATION_CONTEXT,
                $emailVerificationState
            );
            $pendingToken = MixedCaster::string($verificationPayload['pendingToken'] ?? null);
            if ($pendingToken === '') {
                return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
            }

            $result = $this->workosAuthenticationService->authenticateWithEmailVerification($request, $code, $pendingToken);
            $backendUser = $this->userProvisioningService->resolveBackendUser($result['workosUser']);
            $successPath = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
            $this->stateService->remove($emailVerificationState);
            return $this->typo3SessionService->createBackendLoginResponse(
                $request,
                $backendUser,
                $successPath,
                $result['workosUser']->id ?? null,
            );
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\RuntimeException) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend email verify error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->sanitizeErrorMessage($e->getMessage()),
                ]
            );
        }
    }

    private function handleEmailVerifyResend(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $emailVerificationState = $body->trimmedString('emailVerificationState');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/email-verify-resend'
        );

        if ($emailVerificationState === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->translate('error.csrfTokenInvalid'),
                ]
            );
        }

        try {
            $verificationPayload = $this->peekBackendState(
                $request,
                self::EMAIL_VERIFICATION_CONTEXT,
                $emailVerificationState
            );
        } catch (\RuntimeException) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
        }
        $userId = MixedCaster::string($verificationPayload['userId'] ?? null);
        if ($userId === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.verificationSessionExpired'));
        }

        $params = [
            'emailVerificationState' => $emailVerificationState,
        ];

        try {
            $this->workosAuthenticationService->resendEmailVerification($userId);
            $params['workosAuthNotice'] = $this->translate('message.verificationCodeResent');
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend email verify resend error: ' . SecretRedactor::redact($e->getMessage()));
            $params['workosAuthError'] = $this->sanitizeErrorMessage($e->getMessage());
        }

        return $this->redirectToLogin($backendBasePath, $params);
    }

    private function redirectToEmailVerification(
        ServerRequestInterface $request,
        string $backendBasePath,
        EmailVerificationRequiredException $exception
    ): ResponseInterface {
        $issuedState = $this->stateService->issue(
            $request,
            self::EMAIL_VERIFICATION_CONTEXT,
            $backendBasePath,
            [
                'pendingToken' => $exception->pendingAuthenticationToken,
                'email' => $exception->email,
                'userId' => $exception->userId,
            ]
        );

        return $this->redirectToLogin(
            $backendBasePath,
            ['emailVerificationState' => $issuedState['token']],
            $issuedState['cookie'] ?? null
        );
    }

    private function redirectToLoginWithError(string $backendBasePath, string $message): ResponseInterface
    {
        return $this->redirectToLogin($backendBasePath, ['workosAuthError' => $message]);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'password') || str_contains($lower, 'credentials') || str_contains($lower, 'unauthorized')) {
            return $this->translate('error.invalidEmailOrPassword');
        }
        if (str_contains($lower, 'magic') && (str_contains($lower, 'not enabled') || str_contains($lower, 'disabled'))) {
            return $this->translate('error.magicAuthDisabled');
        }
        if (str_contains($lower, 'authentication_method_not_allowed') || str_contains($lower, 'method_not_allowed')) {
            return $this->translate('error.methodNotAllowed');
        }
        if (str_contains($lower, 'user_not_found') || str_contains($lower, 'not found')) {
            return $this->translate('error.userNotFound');
        }
        // Fallback: stable, translatable generic message. The original
        // WorkOS text is already logged via SecretRedactor, so we do not
        // leak it into the redirect URL / browser history / access logs.
        return $this->translate('error.generic');
    }

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        $title = $this->translate('error.loginError');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return new HtmlResponse('<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . $safeMessage . '</p>', $statusCode);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $languageService = $this->languageServiceFactory->createFromUserPreferences(
            $beUser instanceof AbstractUserAuthentication ? $beUser : null
        );
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }

    private function appendCookie(ResponseInterface $response, mixed $cookie): ResponseInterface
    {
        if (!$cookie instanceof Cookie) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', $cookie->__toString());
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function redirectToLogin(
        string $backendBasePath,
        array $parameters,
        ?Cookie $cookie = null,
    ): ResponseInterface {
        $loginUrl = PathUtility::joinBaseAndPath($backendBasePath, '/login');
        $response = new RedirectResponse(
            PathUtility::appendQueryParameters($loginUrl, array_merge(
                ['loginProvider' => '1744276800'],
                $parameters
            )),
            303
        );

        return $this->appendCookie($response, $cookie);
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $callback
     */
    private function processWithBackendRequestToken(
        ServerRequestInterface $request,
        callable $callback
    ): ResponseInterface {
        $requestTokenMiddleware = new RequestTokenMiddleware($this->context);
        $handler = static function (ServerRequestInterface $request) use ($callback): ResponseInterface {
            return $callback($request);
        };

        return $requestTokenMiddleware->process(
            $request,
            new class ($handler) implements RequestHandlerInterface {
                /** @var \Closure(ServerRequestInterface): ResponseInterface */
                private readonly \Closure $handler;

                /**
                 * @param \Closure(ServerRequestInterface): ResponseInterface $handler
                 */
                public function __construct(
                    \Closure $handler,
                ) {
                    $this->handler = $handler;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return ($this->handler)($request);
                }
            }
        );
    }

    private function hasValidBackendRequestToken(): bool
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $securityAspect = SecurityAspect::provideIn($context);
        $requestToken = $securityAspect->getReceivedRequestToken();

        if (!$requestToken instanceof RequestToken || $requestToken->scope !== 'core/user-auth/be') {
            return false;
        }

        if ($requestToken->getSigningSecretIdentifier() !== null) {
            $securityAspect->getSigningSecretResolver()->revokeIdentifier(
                $requestToken->getSigningSecretIdentifier()
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeBackendState(ServerRequestInterface $request, string $context, string $token): array
    {
        return $this->stateService->consume($request, $context, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function peekBackendState(ServerRequestInterface $request, string $context, string $token): array
    {
        return $this->stateService->peek($request, $context, $token);
    }
}
