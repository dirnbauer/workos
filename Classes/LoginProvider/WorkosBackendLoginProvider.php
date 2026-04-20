<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\MixedCaster;
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

        $redirect = MixedCaster::string($request->getQueryParams()['redirect'] ?? null);
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
        $authError = MixedCaster::string($queryParams['workosAuthError'] ?? null);
        $authNotice = MixedCaster::string($queryParams['workosAuthNotice'] ?? null);
        $authErrorDetails = $this->buildAuthErrorDetails($authError, $backendBasePath);

        $passwordAuthUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/password-auth');
        $magicSendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-send');
        $magicVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-verify');
        $emailVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify');
        $emailVerifyResendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify-resend');

        $magicAuthState = trim(MixedCaster::string($queryParams['magicAuthState'] ?? null));
        $magicAuthEmail = '';
        $magicAuthUserId = '';
        if ($magicAuthState !== '') {
            $decoded = base64_decode($magicAuthState, true);
            if ($decoded !== false) {
                try {
                    $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($payload)) {
                        $magicAuthEmail = self::stringFromMixed($payload['email'] ?? null);
                        $magicAuthUserId = self::stringFromMixed($payload['userId'] ?? null);
                    }
                } catch (\JsonException) {
                    $magicAuthState = '';
                }
            } else {
                $magicAuthState = '';
            }
        }

        $emailVerificationState = trim(MixedCaster::string($queryParams['emailVerificationState'] ?? null));
        $emailVerificationEmail = '';
        $emailVerificationUserId = '';
        $emailVerificationPendingToken = '';
        if ($emailVerificationState !== '') {
            $decoded = base64_decode($emailVerificationState, true);
            if ($decoded !== false) {
                try {
                    $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($payload)) {
                        $emailVerificationEmail = self::stringFromMixed($payload['email'] ?? null);
                        $emailVerificationUserId = self::stringFromMixed($payload['userId'] ?? null);
                        $emailVerificationPendingToken = self::stringFromMixed($payload['pendingToken'] ?? null);
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
            'authErrorDetails' => $authErrorDetails,
            'authNotice' => $authNotice,
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->provideRequestTokenJwt(),
        ]);

        return 'Login/WorkosLoginProvider';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAuthErrorDetails(string $rawMessage, string $backendBasePath): ?array
    {
        $rawMessage = trim($rawMessage);
        if ($rawMessage === '') {
            return null;
        }

        $setupUrl = PathUtility::joinBaseAndPath($backendBasePath, '/module/workos/setup');
        $details = [
            'title' => $this->translate('backend.login.error.title'),
            'summary' => $rawMessage,
            'email' => '',
            'userId' => '',
            'hint' => '',
            'actionUrl' => '',
            'actionLabel' => '',
            'isProvisioningDisabled' => false,
        ];

        if (preg_match(
            '/No backend user matched the WorkOS account \(email "([^"]*)", id "([^"]*)"\) and automatic backend provisioning is disabled\./i',
            $rawMessage,
            $matches
        ) === 1) {
            $details['title'] = $this->translate('backend.login.error.notLinked.title');
            $details['summary'] = $this->translate(
                'backend.login.error.notLinked.summary',
                [$matches[1]]
            );
            $details['email'] = $matches[1];
            $details['userId'] = $matches[2];
            $details['hint'] = $this->translate('backend.login.error.notLinked.hint');
            $details['actionUrl'] = $setupUrl;
            $details['actionLabel'] = $this->translate('backend.login.error.notLinked.action');
            $details['isProvisioningDisabled'] = true;
        }

        return $details;
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

    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }

    private function provideRequestTokenJwt(): string
    {
        $nonce = SecurityAspect::provideIn(
            GeneralUtility::makeInstance(Context::class)
        )->provideNonce();

        return RequestToken::create('core/user-auth/be')->toHashSignedJwt($nonce);
    }
}
