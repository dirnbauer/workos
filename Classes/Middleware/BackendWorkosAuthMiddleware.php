<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Middleware\RequestTokenMiddleware;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Security\StateService;
use Webconsulting\WorkosAuth\Security\WorkosErrorMessageResolver;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\ResponseUtility;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\UserProvisioningService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

#[Autoconfigure(public: true)]
final class BackendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string EMAIL_VERIFICATION_CONTEXT = 'backend_email_verification';
    private const string MAGIC_AUTH_CONTEXT = 'backend_magic_auth';

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosAuthenticationService $workosAuthenticationService,
        private readonly UserProvisioningService $userProvisioningService,
        private readonly Typo3SessionService $typo3SessionService,
        private readonly StateService $stateService,
        private readonly WorkosErrorMessageResolver $errorMessageResolver,
        private readonly Context $context,
        private readonly LabelTranslator $translator,
        private readonly RequestTokenService $requestTokenService,
    ) {}

    #[\Override]
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
            return $this->errorResponse($this->translator->translate('error.backendLoginDisabled'), 503);
        }
        if (!$this->configuration->isBackendReady()) {
            return $this->errorResponse($this->translator->translate('error.backendLoginNotSupported'), 503);
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

            return ResponseUtility::withCookie(
                new RedirectResponse($authorizationRequest['url'], 302),
                $authorizationRequest['cookie'],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS backend login error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translator->translate('error.loginError'), 500);
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
                $this->translator->translate('error.backendLoginNotSupported')
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
                    'workosAuthError' => $this->translator->translate($this->errorMessageResolver->resolveAuthentication($exception->getMessage())),
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.enterEmailAndPassword'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.csrfTokenInvalid'));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate($this->errorMessageResolver->resolveAuthentication($e->getMessage())));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.enterEmail'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.csrfTokenInvalid'));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate($this->errorMessageResolver->resolveAuthentication($e->getMessage())));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.invalidMagicAuthSession'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.csrfTokenInvalid'));
        }

        try {
            $magicAuthPayload = $this->stateService->consume($request, self::MAGIC_AUTH_CONTEXT, $magicAuthState);
            $email = MixedCaster::string($magicAuthPayload['email'] ?? null);
            if ($email === '') {
                return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.invalidMagicAuthSession'));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.invalidMagicAuthSession'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend magic auth verify error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate($this->errorMessageResolver->resolveAuthentication($e->getMessage())));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->translator->translate('error.csrfTokenInvalid'),
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
            $verificationPayload = $this->stateService->peek($request, self::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
            $pendingToken = MixedCaster::string($verificationPayload['pendingToken'] ?? null);
            if ($pendingToken === '') {
                return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend email verify error: ' . SecretRedactor::redact($e->getMessage()));
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->translator->translate($this->errorMessageResolver->resolveAuthentication($e->getMessage())),
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
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin(
                $backendBasePath,
                [
                    'emailVerificationState' => $emailVerificationState,
                    'workosAuthError' => $this->translator->translate('error.csrfTokenInvalid'),
                ]
            );
        }

        try {
            $verificationPayload = $this->stateService->peek($request, self::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
        } catch (\RuntimeException) {
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
        }
        $userId = MixedCaster::string($verificationPayload['userId'] ?? null);
        if ($userId === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translator->translate('error.verificationSessionExpired'));
        }

        $params = [
            'emailVerificationState' => $emailVerificationState,
        ];

        try {
            $this->workosAuthenticationService->resendEmailVerification($userId);
            $params['workosAuthNotice'] = $this->translator->translate('message.verificationCodeResent');
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend email verify resend error: ' . SecretRedactor::redact($e->getMessage()));
            $params['workosAuthError'] = $this->translator->translate($this->errorMessageResolver->resolveAuthentication($e->getMessage()));
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

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        return ResponseUtility::htmlError($this->translator->translate('error.loginError'), $message, $statusCode);
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

        return ResponseUtility::withCookie($response, $cookie);
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

                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return ($this->handler)($request);
                }
            }
        );
    }

    private function hasValidBackendRequestToken(): bool
    {
        return $this->requestTokenService->validate(RequestTokenService::BACKEND_LOGIN_SCOPE);
    }
}
