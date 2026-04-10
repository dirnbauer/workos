<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class BackendWorkosAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosAuthenticationService $workosAuthenticationService,
        private UserProvisioningService $userProvisioningService,
        private Typo3SessionService $typo3SessionService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestPath = PathUtility::normalizePath($request->getUri()->getPath());

        if ($requestPath === $this->configuration->getBackendLoginPath()
            || str_ends_with($requestPath, $this->configuration->getBackendLoginPath())
        ) {
            return $this->handleLogin($request);
        }

        if ($requestPath === $this->configuration->getBackendCallbackPath()
            || str_ends_with($requestPath, $this->configuration->getBackendCallbackPath())
        ) {
            return $this->handleCallback($request);
        }

        return $handler->handle($request);
    }

    private function handleLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->configuration->isBackendEnabled()) {
            return $this->errorResponse('Backend WorkOS login is disabled.', 503);
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

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return new HtmlResponse('<h1>WorkOS login error</h1><p>' . $safeMessage . '</p>', $statusCode);
    }
}
