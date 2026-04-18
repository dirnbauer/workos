<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\MixedCaster;
use WebConsulting\WorkosAuth\Security\SecretRedactor;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class FrontendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
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
                MixedCaster::string($queryParams['returnTo'] ?? null),
                $this->configuration->getFrontendSuccessRedirect()
            );

            $requestedScreen = MixedCaster::string($queryParams['screen'] ?? null, 'sign-in');
            $screenHint = in_array($requestedScreen, ['sign-in', 'sign-up'], true) ? $requestedScreen : 'sign-in';

            $requestedProvider = MixedCaster::string($queryParams['provider'] ?? null);
            $provider = in_array($requestedProvider, WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS, true)
                ? $requestedProvider
                : null;

            $loginHint = trim(MixedCaster::string($queryParams['login_hint'] ?? null));
            $organizationId = trim(MixedCaster::string($queryParams['organization'] ?? null));

            return new RedirectResponse(
                $this->workosAuthenticationService->buildFrontendAuthorizationUrl(
                    $request,
                    $returnTo,
                    $screenHint,
                    $provider,
                    $loginHint !== '' ? $loginHint : null,
                    $organizationId !== '' ? $organizationId : null,
                ),
                302
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS frontend login error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translate('error.loginError'), 500);
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
                $authenticationResult['returnTo']
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS frontend callback error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translate('error.loginError'), 403);
        }
    }

    private function handleLogout(ServerRequestInterface $request): ResponseInterface
    {
        $returnTo = PathUtility::sanitizeReturnTo(
            $request,
            MixedCaster::string($request->getQueryParams()['returnTo'] ?? null),
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
}
