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
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\ResponseUtility;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\UserProvisioningService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

#[Autoconfigure(public: true)]
final class FrontendWorkosAuthMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosAuthenticationService $workosAuthenticationService,
        private readonly UserProvisioningService $userProvisioningService,
        private readonly Typo3SessionService $typo3SessionService,
        private readonly LabelTranslator $translator,
    ) {}

    #[\Override]
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
            return $this->errorResponse($this->translator->translate('error.frontendLoginDisabled'), 503);
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
            $authorizationRequest = $this->workosAuthenticationService->buildFrontendAuthorizationUrl(
                $request,
                $returnTo,
                $screenHint,
                $provider,
                $loginHint !== '' ? $loginHint : null,
                $organizationId !== '' ? $organizationId : null,
            );

            return ResponseUtility::withCookie(
                new RedirectResponse($authorizationRequest['url'], 302),
                $authorizationRequest['cookie'],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS frontend login error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translator->translate('error.loginError'), 500);
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
            return $this->errorResponse($this->translator->translate('error.loginError'), 403);
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
        return ResponseUtility::htmlError($this->translator->translate('error.loginError'), $message, $statusCode);
    }
}
