<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class FrontendWorkosAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosAuthenticationService $workosAuthenticationService,
        private UserProvisioningService $userProvisioningService,
        private Typo3SessionService $typo3SessionService,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $relativePath = PathUtility::getPathRelativeToSiteBase(
            $request->getUri()->getPath(),
            $site->getBase()->getPath()
        );

        if ($relativePath === $this->configuration->getFrontendLoginPath()) {
            return $this->handleLogin($request);
        }

        if ($relativePath === $this->configuration->getFrontendCallbackPath()) {
            return $this->handleCallback($request);
        }

        if ($relativePath === $this->configuration->getFrontendLogoutPath()) {
            return $this->handleLogout($request);
        }

        return $handler->handle($request);
    }

    private function handleLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->configuration->isFrontendEnabled()) {
            return $this->errorResponse($this->translate('error.frontendLoginDisabled'), 503);
        }

        try {
            $queryParams = $request->getQueryParams();
            $returnTo = PathUtility::sanitizeReturnTo(
                $request,
                (string)($queryParams['returnTo'] ?? ''),
                $this->configuration->getFrontendSuccessRedirect()
            );

            $screenHint = in_array($queryParams['screen'] ?? '', ['sign-in', 'sign-up'], true)
                ? $queryParams['screen']
                : 'sign-in';

            $allowedProviders = ['GoogleOAuth', 'MicrosoftOAuth', 'GitHubOAuth', 'AppleOAuth'];
            $provider = in_array($queryParams['provider'] ?? '', $allowedProviders, true)
                ? $queryParams['provider']
                : null;

            $loginHint = isset($queryParams['login_hint']) ? trim((string)$queryParams['login_hint']) : null;
            $organizationId = isset($queryParams['organization']) ? trim((string)$queryParams['organization']) : null;

            return new RedirectResponse(
                $this->workosAuthenticationService->buildFrontendAuthorizationUrl(
                    $request,
                    $returnTo,
                    $screenHint,
                    $provider,
                    $loginHint ?: null,
                    $organizationId ?: null,
                ),
                302
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 500);
        }
    }

    private function handleCallback(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $authenticationResult = $this->workosAuthenticationService->handleCallback($request, 'frontend');
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($authenticationResult['workosUser']);

            return $this->typo3SessionService->createFrontendLoginResponse(
                $request,
                $frontendUser,
                (string)$authenticationResult['returnTo']
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 403);
        }
    }

    private function handleLogout(ServerRequestInterface $request): ResponseInterface
    {
        $returnTo = PathUtility::sanitizeReturnTo(
            $request,
            (string)($request->getQueryParams()['returnTo'] ?? ''),
            $this->configuration->getFrontendSuccessRedirect()
        );

        return $this->typo3SessionService->createFrontendLogoutResponse($request, $returnTo);
    }

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        $title = $this->translate('error.loginError');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return new HtmlResponse('<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . $safeMessage . '</p>', $statusCode);
    }

    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }
}
