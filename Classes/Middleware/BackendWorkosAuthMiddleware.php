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
use Webconsulting\WorkosAuth\Domain\AuthenticatedSession;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Exception\AccountNotLinkedException;
use Webconsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use Webconsulting\WorkosAuth\Exception\ImpersonationNotAllowedException;
use Webconsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider;
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

/**
 * Backend login endpoints below the TYPO3 entry point (e.g. `/typo3`):
 * the configurable AuthKit redirect + callback pair and five fixed POST
 * endpoints used by the WorkOS login provider form.
 *
 * Messages for the login screen never travel as text in the URL: they are
 * stored server-side (StateService, bound to this browser) and the login
 * provider shows them once. A crafted login link can therefore not put
 * words of its own onto the TYPO3 login page.
 */
#[Autoconfigure(public: true)]
final class BackendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const string PASSWORD_AUTH_PATH = '/workos-auth/backend/password-auth';
    public const string MAGIC_AUTH_SEND_PATH = '/workos-auth/backend/magic-auth-send';
    public const string MAGIC_AUTH_VERIFY_PATH = '/workos-auth/backend/magic-auth-verify';
    public const string EMAIL_VERIFY_PATH = '/workos-auth/backend/email-verify';
    public const string EMAIL_VERIFY_RESEND_PATH = '/workos-auth/backend/email-verify-resend';

    /**
     * StateService contexts of the two multi-step flows and of the one-shot
     * login screen message (shared with the login provider).
     */
    public const string EMAIL_VERIFICATION_CONTEXT = 'backend_email_verification';
    public const string MAGIC_AUTH_CONTEXT = 'backend_magic_auth';
    public const string LOGIN_MESSAGE_CONTEXT = 'backend_login_message';

    /**
     * Query parameter carrying the token of the one-shot login screen message.
     */
    public const string LOGIN_MESSAGE_PARAMETER = 'workosMessage';

    /**
     * @var list<string>
     */
    private const array POST_ENDPOINTS = [
        self::PASSWORD_AUTH_PATH,
        self::MAGIC_AUTH_SEND_PATH,
        self::MAGIC_AUTH_VERIFY_PATH,
        self::EMAIL_VERIFY_PATH,
        self::EMAIL_VERIFY_RESEND_PATH,
    ];

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

        if (str_ends_with($requestPath, $this->configuration->getBackendLoginPath())) {
            return $this->handleLogin($request, PathUtility::guessBasePathFromMatchedPath($requestPath, $this->configuration->getBackendLoginPath()));
        }

        if (str_ends_with($requestPath, $this->configuration->getBackendCallbackPath())) {
            return $this->handleCallback($request, PathUtility::guessBasePathFromMatchedPath($requestPath, $this->configuration->getBackendCallbackPath()));
        }

        if ($request->getMethod() === 'POST') {
            foreach (self::POST_ENDPOINTS as $endpoint) {
                if (str_ends_with($requestPath, $endpoint)) {
                    $backendBasePath = PathUtility::guessBasePathFromMatchedPath($requestPath, $endpoint);

                    return $this->withBackendRequestToken(
                        $request,
                        fn(ServerRequestInterface $request): ResponseInterface => $this->handlePost($endpoint, $request, $backendBasePath)
                    );
                }
            }
        }

        return $handler->handle($request);
    }

    private function handlePost(string $endpoint, ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        return match ($endpoint) {
            self::PASSWORD_AUTH_PATH => $this->handlePasswordAuth($request, $backendBasePath),
            self::MAGIC_AUTH_SEND_PATH => $this->handleMagicAuthSend($request, $backendBasePath),
            self::MAGIC_AUTH_VERIFY_PATH => $this->handleMagicAuthVerify($request, $backendBasePath),
            self::EMAIL_VERIFY_PATH => $this->handleEmailVerify($request, $backendBasePath),
            self::EMAIL_VERIFY_RESEND_PATH => $this->handleEmailVerifyResend($request, $backendBasePath),
            default => throw new \LogicException('Unknown backend WorkOS endpoint ' . $endpoint, 1758200001),
        };
    }

    private function handleLogin(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        if (!$this->configuration->isBackendEnabled()) {
            return $this->errorResponse($this->translator->translate('error.backendLoginDisabled'), 503);
        }
        if (!$this->configuration->isBackendReady()) {
            return $this->errorResponse($this->translator->translate('error.backendLoginNotSupported'), 503);
        }

        $queryParams = $request->getQueryParams();
        $returnTo = PathUtility::sanitizeReturnTo(
            $request,
            MixedCaster::string($queryParams['returnTo'] ?? null),
            PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath())
        );
        $loginHint = trim(MixedCaster::string($queryParams['login_hint'] ?? null));
        $organizationId = trim(MixedCaster::string($queryParams['organization'] ?? null));

        try {
            $authorizationRequest = $this->workosAuthenticationService->buildBackendAuthorizationUrl(
                $request,
                $backendBasePath,
                $returnTo,
                $loginHint !== '' ? $loginHint : null,
                SocialProvider::tryFrom(MixedCaster::string($queryParams['provider'] ?? null)),
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

    private function handleCallback(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        if (!$this->configuration->isBackendReady()) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.backendLoginNotSupported'));
        }

        try {
            $result = $this->workosAuthenticationService->handleCallback($request, LoginContext::Backend);

            return $this->createLoginResponse($request, $result['session'], $result['returnTo']);
        } catch (\Throwable $exception) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $exception, 'callback');
        }
    }

    private function handlePasswordAuth(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');

        if ($email === '' || $password === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.enterEmailAndPassword'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.csrfTokenInvalid'));
        }

        try {
            $session = $this->workosAuthenticationService->authenticateWithPassword($request, $email, $password);

            return $this->createLoginResponse($request, $session, $this->successUrl($backendBasePath));
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\Throwable $e) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $e, 'password auth');
        }
    }

    private function handleMagicAuthSend(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        $email = RequestBody::fromRequest($request)->trimmedString('email');

        if ($email === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.enterEmail'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.csrfTokenInvalid'));
        }

        try {
            $this->workosAuthenticationService->sendMagicAuthCode($email);
            $issuedState = $this->stateService->issue($request, self::MAGIC_AUTH_CONTEXT, $backendBasePath, ['email' => $email]);

            return $this->redirectToLogin($request, $backendBasePath, ['magicAuthState' => $issuedState['token']], cookies: [$issuedState['cookie']]);
        } catch (\Throwable $e) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $e, 'magic auth send');
        }
    }

    private function handleMagicAuthVerify(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $code = $body->trimmedString('code');
        $magicAuthState = $body->trimmedString('magicAuthState');

        if ($code === '' || $magicAuthState === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.invalidMagicAuthSession'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.csrfTokenInvalid'));
        }

        try {
            $payload = $this->stateService->consume($request, self::MAGIC_AUTH_CONTEXT, $magicAuthState);
        } catch (\RuntimeException) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.invalidMagicAuthSession'));
        }
        $email = MixedCaster::string($payload['email'] ?? null);
        if ($email === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.invalidMagicAuthSession'));
        }

        try {
            $session = $this->workosAuthenticationService->authenticateWithMagicAuth($request, $code, $email);

            return $this->createLoginResponse($request, $session, $this->successUrl($backendBasePath));
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\Throwable $e) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $e, 'magic auth verify');
        }
    }

    private function handleEmailVerify(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        $body = RequestBody::fromRequest($request);
        $code = $body->trimmedString('code');
        $emailVerificationState = $body->trimmedString('emailVerificationState');
        $backToVerification = ['emailVerificationState' => $emailVerificationState];

        if ($emailVerificationState === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin($request, $backendBasePath, $backToVerification, error: $this->translator->translate('error.csrfTokenInvalid'));
        }
        if ($code === '') {
            return $this->redirectToLogin($request, $backendBasePath, $backToVerification);
        }

        try {
            $payload = $this->stateService->peek($request, self::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
        } catch (\RuntimeException) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }
        $pendingToken = MixedCaster::string($payload['pendingToken'] ?? null);
        if ($pendingToken === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }

        try {
            $session = $this->workosAuthenticationService->authenticateWithEmailVerification($request, $code, $pendingToken);
            $this->stateService->remove($emailVerificationState);

            return $this->createLoginResponse($request, $session, $this->successUrl($backendBasePath));
        } catch (EmailVerificationRequiredException $e) {
            return $this->redirectToEmailVerification($request, $backendBasePath, $e);
        } catch (\Throwable $e) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $e, 'email verify', $backToVerification);
        }
    }

    private function handleEmailVerifyResend(ServerRequestInterface $request, string $backendBasePath): ResponseInterface
    {
        $emailVerificationState = RequestBody::fromRequest($request)->trimmedString('emailVerificationState');
        $backToVerification = ['emailVerificationState' => $emailVerificationState];

        if ($emailVerificationState === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidBackendRequestToken()) {
            return $this->redirectToLogin($request, $backendBasePath, $backToVerification, error: $this->translator->translate('error.csrfTokenInvalid'));
        }

        try {
            $payload = $this->stateService->peek($request, self::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
        } catch (\RuntimeException) {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }
        $userId = MixedCaster::string($payload['userId'] ?? null);
        if ($userId === '') {
            return $this->redirectToLogin($request, $backendBasePath, error: $this->translator->translate('error.verificationSessionExpired'));
        }

        try {
            $this->workosAuthenticationService->resendEmailVerification($userId);

            return $this->redirectToLogin($request, $backendBasePath, $backToVerification, notice: $this->translator->translate('message.verificationCodeResent'));
        } catch (\Throwable $e) {
            return $this->redirectToLoginWithFailure($request, $backendBasePath, $e, 'email verify resend', $backToVerification);
        }
    }

    private function createLoginResponse(ServerRequestInterface $request, AuthenticatedSession $session, string $redirectUrl): ResponseInterface
    {
        if ($session->isImpersonated()) {
            $this->logger?->warning(sprintf(
                'Refused a WorkOS impersonation session for the TYPO3 backend: %s impersonated %s (reason: %s).',
                $session->impersonatorEmail,
                $session->user->email,
                $session->impersonationReason ?? 'none given',
            ));
            throw new ImpersonationNotAllowedException('Impersonated WorkOS sessions cannot sign in to the TYPO3 backend.', 1758200002);
        }

        return $this->typo3SessionService->createBackendLoginResponse(
            $request,
            $this->userProvisioningService->resolve(LoginContext::Backend, $session->user),
            $redirectUrl,
            $session->user->id,
            $session->sessionId,
        );
    }

    private function redirectToEmailVerification(
        ServerRequestInterface $request,
        string $backendBasePath,
        EmailVerificationRequiredException $exception,
    ): ResponseInterface {
        $issuedState = $this->stateService->issue($request, self::EMAIL_VERIFICATION_CONTEXT, $backendBasePath, [
            'pendingToken' => $exception->pendingAuthenticationToken,
            'email' => $exception->email,
            'userId' => $exception->userId,
        ]);

        return $this->redirectToLogin($request, $backendBasePath, ['emailVerificationState' => $issuedState['token']], cookies: [$issuedState['cookie']]);
    }

    private function successUrl(string $backendBasePath): string
    {
        return PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
    }

    /**
     * Logs a failed step and sends the browser back to the login screen with
     * a message the editor can act on. The raw error only reaches the log.
     *
     * @param array<string, string> $parameters
     */
    private function redirectToLoginWithFailure(
        ServerRequestInterface $request,
        string $backendBasePath,
        \Throwable $exception,
        string $step,
        array $parameters = [],
    ): ResponseInterface {
        $this->logger?->error(sprintf('WorkOS backend %s error: %s', $step, SecretRedactor::redact($exception->getMessage())));

        $details = [];
        if ($exception instanceof AccountNotLinkedException) {
            $details = ['email' => $exception->email, 'userId' => $exception->workosUserId];
        }

        return $this->redirectToLogin(
            $request,
            $backendBasePath,
            $parameters,
            error: $this->translator->translate($this->errorMessageResolver->resolveLogin($exception)),
            details: $details,
        );
    }

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        return ResponseUtility::htmlError($this->translator->translate('error.loginError'), $message, $statusCode);
    }

    /**
     * @param array<string, string> $parameters query parameters of the login screen
     * @param array<string, string> $details structured context for an error, e.g. the unlinked account
     * @param list<Cookie|null> $cookies binding cookies of states issued for this redirect
     */
    private function redirectToLogin(
        ServerRequestInterface $request,
        string $backendBasePath,
        array $parameters = [],
        string $error = '',
        string $notice = '',
        array $details = [],
        array $cookies = [],
    ): ResponseInterface {
        if ($error !== '' || $notice !== '') {
            $message = $this->stateService->issue($request, self::LOGIN_MESSAGE_CONTEXT, $backendBasePath, [
                'error' => $error,
                'notice' => $notice,
                'details' => $details,
            ]);
            $parameters[self::LOGIN_MESSAGE_PARAMETER] = $message['token'];
            $cookies[] = $message['cookie'];
        }

        $response = new RedirectResponse(
            PathUtility::appendQueryParameters(
                PathUtility::joinBaseAndPath($backendBasePath, '/login'),
                ['loginProvider' => WorkosBackendLoginProvider::IDENTIFIER] + $parameters
            ),
            303
        );
        foreach ($cookies as $cookie) {
            $response = ResponseUtility::withCookie($response, $cookie);
        }

        return $response;
    }

    /**
     * Run TYPO3's RequestTokenMiddleware in front of the callback so the
     * hashed `__RequestToken` of the login form is verified and available
     * through the SecurityAspect.
     *
     * @param \Closure(ServerRequestInterface): ResponseInterface $callback
     */
    private function withBackendRequestToken(ServerRequestInterface $request, \Closure $callback): ResponseInterface
    {
        $handler = new class ($callback) implements RequestHandlerInterface {
            /**
             * @param \Closure(ServerRequestInterface): ResponseInterface $callback
             */
            public function __construct(private readonly \Closure $callback) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };

        return new RequestTokenMiddleware($this->context)->process($request, $handler);
    }

    private function hasValidBackendRequestToken(): bool
    {
        return $this->requestTokenService->validate(RequestTokenService::BACKEND_LOGIN_SCOPE);
    }
}
