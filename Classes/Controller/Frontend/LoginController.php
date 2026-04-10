<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class LoginController extends ActionController
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
    ) {}

    public function showAction(): ResponseInterface
    {
        $site = $this->request->getAttribute('site');
        $siteBasePath = $site !== null ? (string)$site->getBase()->getPath() : '';
        $currentUrl = method_exists($this->request, 'getUri') ? (string)$this->request->getUri() : '';

        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);
        $displayName = $isLoggedIn
            ? (string)($frontendUser->user['name'] ?? $frontendUser->user['username'] ?? $frontendUser->user['email'] ?? '')
            : '';

        $loginPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLoginPath());
        $logoutPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLogoutPath());

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'isLoggedIn' => $isLoggedIn,
            'displayName' => $displayName,
            'loginUrl' => PathUtility::appendQueryParameters($loginPath, ['returnTo' => $currentUrl]),
            'logoutUrl' => PathUtility::appendQueryParameters($logoutPath, ['returnTo' => $currentUrl]),
        ]);

        return $this->htmlResponse();
    }
}
