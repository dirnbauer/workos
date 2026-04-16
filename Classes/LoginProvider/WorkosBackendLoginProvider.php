<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class WorkosBackendLoginProvider implements LoginProviderInterface
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly PageRenderer $pageRenderer,
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
        $authNotice = (string)($queryParams['workosAuthNotice'] ?? '');

        $passwordAuthUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/password-auth');
        $magicSendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-send');
        $magicVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-verify');
        $emailVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify');
        $emailVerifyResendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify-resend');

        $magicAuthState = trim((string)($queryParams['magicAuthState'] ?? ''));
        $magicAuthEmail = '';
        $magicAuthUserId = '';
        if ($magicAuthState !== '') {
            $decoded = base64_decode($magicAuthState, true);
            if ($decoded !== false) {
                try {
                    $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($payload)) {
                        $magicAuthEmail = (string)($payload['email'] ?? '');
                        $magicAuthUserId = (string)($payload['userId'] ?? '');
                    }
                } catch (\JsonException) {
                    $magicAuthState = '';
                }
            } else {
                $magicAuthState = '';
            }
        }

        $emailVerificationState = trim((string)($queryParams['emailVerificationState'] ?? ''));
        $emailVerificationEmail = '';
        $emailVerificationUserId = '';
        $emailVerificationPendingToken = '';
        if ($emailVerificationState !== '') {
            $decoded = base64_decode($emailVerificationState, true);
            if ($decoded !== false) {
                try {
                    $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($payload)) {
                        $emailVerificationEmail = (string)($payload['email'] ?? '');
                        $emailVerificationUserId = (string)($payload['userId'] ?? '');
                        $emailVerificationPendingToken = (string)($payload['pendingToken'] ?? '');
                    }
                } catch (\JsonException) {
                    $emailVerificationState = '';
                }
            } else {
                $emailVerificationState = '';
            }
        }
        if ($emailVerificationPendingToken === '') {
            $emailVerificationState = '';
        }

        $socialProviders = [
            ['key' => 'GoogleOAuth', 'label' => $this->translate('provider.google'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GoogleOAuth'])],
            ['key' => 'MicrosoftOAuth', 'label' => $this->translate('provider.microsoft'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'MicrosoftOAuth'])],
            ['key' => 'GitHubOAuth', 'label' => $this->translate('provider.github'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GitHubOAuth'])],
            ['key' => 'AppleOAuth', 'label' => $this->translate('provider.apple'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'AppleOAuth'])],
        ];

        if ($this->configuration->isBackendEnabled() && $this->configuration->isBackendReady()) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@webconsulting/workos-auth/workos-login.js')
            );
        }

        $view->assignMultiple([
            'enabled' => $this->configuration->isBackendEnabled(),
            'configured' => $this->configuration->isBackendReady(),
            'loginUrl' => $loginUrl,
            'setupUrl' => PathUtility::joinBaseAndPath($backendBasePath, '/module/workos/setup'),
            'passwordAuthUrl' => $passwordAuthUrl,
            'magicSendUrl' => $magicSendUrl,
            'magicVerifyUrl' => $magicVerifyUrl,
            'magicAuthState' => $magicAuthState,
            'magicAuthEmail' => $magicAuthEmail,
            'magicAuthUserId' => $magicAuthUserId,
            'emailVerifyUrl' => $emailVerifyUrl,
            'emailVerifyResendUrl' => $emailVerifyResendUrl,
            'emailVerificationState' => $emailVerificationState,
            'emailVerificationEmail' => $emailVerificationEmail,
            'emailVerificationUserId' => $emailVerificationUserId,
            'emailVerificationPendingToken' => $emailVerificationPendingToken,
            'socialProviders' => $socialProviders,
            'authError' => $authError,
            'authNotice' => $authNotice,
        ]);

        return 'Login/WorkosLoginProvider';
    }

    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }
}
