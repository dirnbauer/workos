<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class WorkosBackendLoginProvider implements LoginProviderInterface
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
    ) {}

    public function modifyView(ServerRequestInterface $request, ViewInterface $view): string
    {
        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $loginUrl = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendLoginPath());

        $redirect = (string)($request->getQueryParams()['redirect'] ?? '');
        if ($redirect !== '') {
            $loginUrl = PathUtility::appendQueryParameters($loginUrl, ['returnTo' => $redirect]);
        }

        if ($view instanceof FluidViewAdapter) {
            $templatePaths = $view->getRenderingContext()->getTemplatePaths();
            $templateRootPaths = $templatePaths->getTemplateRootPaths();
            $templateRootPaths[] = 'EXT:workos_auth/Resources/Private/Templates';
            $templatePaths->setTemplateRootPaths($templateRootPaths);
        }

        $view->assignMultiple([
            'enabled' => $this->configuration->isBackendEnabled(),
            'configured' => $this->configuration->isBackendReady(),
            'loginUrl' => $loginUrl,
            'setupUrl' => PathUtility::joinBaseAndPath($backendBasePath, '/module/system/workos-auth'),
        ]);

        return 'Login/WorkosLoginProvider';
    }
}
