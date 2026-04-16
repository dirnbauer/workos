<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class BackendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosAuthenticationService $workosAuthenticationService,
        private UserProvisioningService $userProvisioningService,
        private Typo3SessionService $typo3SessionService,
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
            return $this->handlePasswordAuth($request);
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/magic-auth-send') && $request->getMethod() === 'POST') {
            return $this->handleMagicAuthSend($request);
        }

        if ($this->pathMatches($requestPath, '/workos-auth/backend/magic-auth-verify') && $request->getMethod() === 'POST') {
            return $this->handleMagicAuthVerify($request);
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

        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            $this->configuration->getBackendLoginPath()
        );

        $fallbackReturnTo = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
        $returnTo = PathUtility::sanitizeReturnTo(
            $request,
            (string)($request->getQueryParams()['returnTo'] ?? ''),
            $fallbackReturnTo
        );

        try {
            return new RedirectResponse(
                $this->workosAuthenticationService->buildBackendAuthorizationUrl($request, $backendBasePath, $returnTo),
                302
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 500);
        }
    }

    private function handleCallback(ServerRequestInterface $request): ResponseInterface
    {
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            $this->configuration->getBackendCallbackPath()
        );

        try {
            $authenticationResult = $this->workosAuthenticationService->handleCallback($request, 'backend');
            $backendUser = $this->userProvisioningService->resolveBackendUser($authenticationResult['workosUser']);

            return $this->typo3SessionService->createBackendLoginResponse(
                $request,
                $backendUser,
                (string)$authenticationResult['returnTo']
            );
        } catch (\Throwable $exception) {
            $fallbackLoginPath = PathUtility::joinBaseAndPath($backendBasePath, '/login');
            $response = new RedirectResponse(
                PathUtility::appendQueryParameters($fallbackLoginPath, ['workosAuthError' => '1']),
                303
            );

            return $response->withAddedHeader('X-WorkOS-Auth-Error', $exception->getMessage());
        }
    }

    private function handlePasswordAuth(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/password-auth'
        );

        if ($email === '' || $password === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.enterEmailAndPassword'));
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithPassword($request, $email, $password);
            $backendUser = $this->userProvisioningService->resolveBackendUser($result['workosUser']);
            $successPath = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
            return $this->typo3SessionService->createBackendLoginResponse($request, $backendUser, $successPath);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend password auth error: ' . $e->getMessage());
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function handleMagicAuthSend(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = trim((string)($body['email'] ?? ''));
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/magic-auth-send'
        );

        if ($email === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.enterEmail'));
        }

        try {
            $magicAuth = $this->workosAuthenticationService->sendMagicAuthCode($email);
            $state = base64_encode(json_encode([
                'userId' => $magicAuth['userId'],
                'email' => $email,
            ], JSON_THROW_ON_ERROR));

            $loginUrl = PathUtility::joinBaseAndPath($backendBasePath, '/login');
            return new RedirectResponse(
                PathUtility::appendQueryParameters($loginUrl, ['magicAuthState' => $state]),
                303
            );
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend magic auth send error: ' . $e->getMessage());
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function handleMagicAuthVerify(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $code = trim((string)($body['code'] ?? ''));
        $userId = trim((string)($body['userId'] ?? ''));
        $backendBasePath = PathUtility::guessBasePathFromMatchedPath(
            $request->getUri()->getPath(),
            '/workos-auth/backend/magic-auth-verify'
        );

        if ($code === '' || $userId === '') {
            return $this->redirectToLoginWithError($backendBasePath, $this->translate('error.invalidMagicAuthSession'));
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithMagicAuth($request, $code, $userId);
            $backendUser = $this->userProvisioningService->resolveBackendUser($result['workosUser']);
            $successPath = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendSuccessPath());
            return $this->typo3SessionService->createBackendLoginResponse($request, $backendUser, $successPath);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS backend magic auth verify error: ' . $e->getMessage());
            return $this->redirectToLoginWithError($backendBasePath, $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function redirectToLoginWithError(string $backendBasePath, string $message): ResponseInterface
    {
        $loginUrl = PathUtility::joinBaseAndPath($backendBasePath, '/login');
        return new RedirectResponse(
            PathUtility::appendQueryParameters($loginUrl, ['workosAuthError' => $message]),
            303
        );
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
        return $message;
    }

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        $title = $this->translate('error.loginError');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return new HtmlResponse('<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . $safeMessage . '</p>', $statusCode);
    }

    private function translate(string $key): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return $languageService->sL('workos_auth.messages:' . $key) ?: $key;
    }
}
