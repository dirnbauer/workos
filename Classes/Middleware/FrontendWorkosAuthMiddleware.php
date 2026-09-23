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
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\ResponseUtility;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\UserProvisioningService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

/**
 * Frontend AuthKit endpoints relative to the site base: redirect to the
 * hosted login, OAuth callback and logout.
 */
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

        $relativePath = PathUtility::getPathRelativeToSiteBase($request->getUri()->getPath(), $site->getBase()->getPath());

        return match ($relativePath) {
            $this->configuration->getFrontendLoginPath() => $this->handleLogin($request),
            $this->configuration->getFrontendCallbackPath() => $this->handleCallback($request),
            $this->configuration->getFrontendLogoutPath() => $this->handleLogout($request),
            default => $handler->handle($request),
        };
    }

    private function handleLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->configuration->isFrontendEnabled()) {
            return $this->errorResponse($this->translator->translate('error.frontendLoginDisabled'), 503);
        }

        $queryParams = $request->getQueryParams();
        $screenHint = MixedCaster::string($queryParams['screen'] ?? null, 'sign-in');
        $loginHint = trim(MixedCaster::string($queryParams['login_hint'] ?? null));
        $organizationId = trim(MixedCaster::string($queryParams['organization'] ?? null));

        try {
            $authorizationRequest = $this->workosAuthenticationService->buildFrontendAuthorizationUrl(
                $request,
                $this->sanitizeReturnTo($request),
                in_array($screenHint, ['sign-in', 'sign-up'], true) ? $screenHint : 'sign-in',
                SocialProvider::tryFrom(MixedCaster::string($queryParams['provider'] ?? null)),
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
            $result = $this->workosAuthenticationService->handleCallback($request, LoginContext::Frontend);
            $session = $result['session'];
            if ($session->isImpersonated()) {
                $this->logger?->notice(sprintf(
                    'WorkOS impersonation session on the frontend: %s signed in as %s (reason: %s).',
                    $session->impersonatorEmail,
                    $session->user->email,
                    $session->impersonationReason ?? 'none given',
                ));
            }

            return $this->typo3SessionService->createFrontendLoginResponse(
                $request,
                $this->userProvisioningService->resolve(LoginContext::Frontend, $session->user),
                $result['returnTo'],
                $session->sessionId,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS frontend callback error: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->errorResponse($this->translator->translate('error.loginError'), 403);
        }
    }

    private function handleLogout(ServerRequestInterface $request): ResponseInterface
    {
        return $this->typo3SessionService->createFrontendLogoutResponse($request, $this->sanitizeReturnTo($request));
    }

    private function sanitizeReturnTo(ServerRequestInterface $request): string
    {
        return PathUtility::sanitizeReturnTo(
            $request,
            MixedCaster::string($request->getQueryParams()['returnTo'] ?? null),
            $this->configuration->getFrontendSuccessRedirect()
        );
    }

    private function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        return ResponseUtility::htmlError($this->translator->translate('error.loginError'), $message, $statusCode);
    }
}
