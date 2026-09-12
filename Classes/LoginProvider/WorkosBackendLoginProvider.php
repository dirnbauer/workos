<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\LoginProvider;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\StateService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;

#[Autoconfigure(public: true)]
final readonly class WorkosBackendLoginProvider implements LoginProviderInterface
{
    private const string EMAIL_VERIFICATION_CONTEXT = 'backend_email_verification';
    private const string MAGIC_AUTH_CONTEXT = 'backend_magic_auth';

    public function __construct(
        private WorkosConfiguration $configuration,
        private StateService $stateService,
        private LabelTranslator $translator,
        private PageRenderer $pageRenderer,
        private RequestTokenService $requestTokenService,
    ) {}

    #[\Override]
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

        $passwordAuthUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/password-auth');
        $magicSendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-send');
        $magicVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/magic-auth-verify');
        $emailVerifyUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify');
        $emailVerifyResendUrl = PathUtility::joinBaseAndPath($backendBasePath, '/workos-auth/backend/email-verify-resend');

        $magicAuthState = trim(MixedCaster::string($queryParams['magicAuthState'] ?? null));
        $magicAuthEmail = '';
        if ($magicAuthState !== '') {
            try {
                $payload = $this->stateService->peek($request, self::MAGIC_AUTH_CONTEXT, $magicAuthState);
                $magicAuthEmail = MixedCaster::string($payload['email'] ?? null);
                if ($magicAuthEmail === '') {
                    $magicAuthState = '';
                }
            } catch (\RuntimeException) {
                $magicAuthState = '';
                if ($authError === '') {
                    $authError = $this->translator->translate('error.invalidMagicAuthSession');
                }
            }
        }

        $emailVerificationState = trim(MixedCaster::string($queryParams['emailVerificationState'] ?? null));
        $emailVerificationEmail = '';
        $emailVerificationCanResend = false;
        if ($emailVerificationState !== '') {
            try {
                $payload = $this->stateService->peek($request, self::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
                $emailVerificationEmail = MixedCaster::string($payload['email'] ?? null);
                $pendingToken = MixedCaster::string($payload['pendingToken'] ?? null);
                $emailVerificationCanResend = MixedCaster::string($payload['userId'] ?? null) !== '';
                if ($emailVerificationEmail === '' || $pendingToken === '') {
                    $emailVerificationState = '';
                    $emailVerificationCanResend = false;
                }
            } catch (\RuntimeException) {
                $emailVerificationState = '';
                $emailVerificationCanResend = false;
                if ($authError === '') {
                    $authError = $this->translator->translate('error.verificationSessionExpired');
                }
            }
        }
        $authErrorDetails = $this->buildAuthErrorDetails($authError, $backendBasePath);

        $socialProviders = [
            ['key' => 'GoogleOAuth', 'label' => $this->translator->translate('provider.google'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GoogleOAuth'])],
            ['key' => 'MicrosoftOAuth', 'label' => $this->translator->translate('provider.microsoft'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'MicrosoftOAuth'])],
            ['key' => 'GitHubOAuth', 'label' => $this->translator->translate('provider.github'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'GitHubOAuth'])],
            ['key' => 'AppleOAuth', 'label' => $this->translator->translate('provider.apple'), 'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => 'AppleOAuth'])],
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
            'emailVerifyUrl' => $emailVerifyUrl,
            'emailVerifyResendUrl' => $emailVerifyResendUrl,
            'emailVerificationState' => $emailVerificationState,
            'emailVerificationEmail' => $emailVerificationEmail,
            'emailVerificationCanResend' => $emailVerificationCanResend,
            'socialProviders' => $socialProviders,
            'authError' => $authError,
            'authErrorDetails' => $authErrorDetails,
            'authNotice' => $authNotice,
            'backendCookieSameSite' => $this->configuration->getBackendCookieSameSite(),
            'backendCookieSameSiteCompatible' => $this->configuration->isBackendCookieSameSiteCompatible(),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(RequestTokenService::BACKEND_LOGIN_SCOPE),
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
            'title' => $this->translator->translate('backend.login.error.title'),
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
            $details['title'] = $this->translator->translate('backend.login.error.notLinked.title');
            $details['summary'] = $this->translator->translate(
                'backend.login.error.notLinked.summary',
                ['email' => $matches[1]]
            );
            $details['email'] = $matches[1];
            $details['userId'] = $matches[2];
            $details['hint'] = $this->translator->translate('backend.login.error.notLinked.hint');
            $details['actionUrl'] = $setupUrl;
            $details['actionLabel'] = $this->translator->translate('backend.login.error.notLinked.action');
            $details['isProvisioningDisabled'] = true;
        }

        return $details;
    }
}
