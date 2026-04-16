<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class WorkosBackendLoginProvider implements LoginProviderInterface
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly LanguageServiceFactory $languageServiceFactory,
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

            $partialRootPaths = $templatePaths->getPartialRootPaths();
            $partialRootPaths[] = 'EXT:workos_auth/Resources/Private/Partials';
            $templatePaths->setPartialRootPaths($partialRootPaths);
        }

        $queryParams = $request->getQueryParams();

        $authError = (string)($queryParams['workosAuthError'] ?? '');

        $magicAuthState = null;
        $magicAuthEmail = '';
        $magicAuthUserId = '';
        $stateParam = (string)($queryParams['magicAuthState'] ?? '');
        if ($stateParam !== '') {
            try {
                $decoded = json_decode(base64_decode($stateParam, true) ?: '', true, 4, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && !empty($decoded['email']) && !empty($decoded['userId'])) {
                    $magicAuthState = $stateParam;
                    $magicAuthEmail = (string)$decoded['email'];
                    $magicAuthUserId = (string)$decoded['userId'];
                }
            } catch (\JsonException) {
            }
        }

        $passwordAuthUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/password-auth');
        $magicAuthSendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-send');
        $magicAuthVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-verify');

        $socialProviders = [
            ['key' => 'GoogleOAuth', 'label' => $this->translate('provider.google'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GoogleOAuth'])],
            ['key' => 'MicrosoftOAuth', 'label' => $this->translate('provider.microsoft'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'MicrosoftOAuth'])],
            ['key' => 'GitHubOAuth', 'label' => $this->translate('provider.github'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GitHubOAuth'])],
            ['key' => 'AppleOAuth', 'label' => $this->translate('provider.apple'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'AppleOAuth'])],
        ];

        $view->assignMultiple([
            'enabled' => $this->configuration->isBackendEnabled(),
            'configured' => $this->configuration->isBackendReady(),
            'loginUrl' => $loginUrl,
            'setupUrl' => PathUtility::joinBaseAndPath($backendBasePath, '/module/system/workos-auth'),
            'passwordAuthUrl' => $passwordAuthUrl,
            'magicAuthSendUrl' => $magicAuthSendUrl,
            'magicAuthVerifyUrl' => $magicAuthVerifyUrl,
            'socialProviders' => $socialProviders,
            'authError' => $authError,
            'magicAuthState' => $magicAuthState,
            'magicAuthEmail' => $magicAuthEmail,
            'magicAuthUserId' => $magicAuthUserId,
        ]);

        return 'Login/WorkosLoginProvider';
    }

    private function translate(string $key): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return $languageService->sL('LLL:EXT:workos_auth/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }
}
