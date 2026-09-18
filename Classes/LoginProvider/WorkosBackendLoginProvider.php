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
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Middleware\BackendWorkosAuthMiddleware;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\StateService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;

/**
 * "Continue with WorkOS" tab on the TYPO3 backend login screen. The form
 * posts to the endpoints of {@see BackendWorkosAuthMiddleware}; multi-step
 * state (magic auth code, email verification) is looked up via StateService.
 */
#[Autoconfigure(public: true)]
final readonly class WorkosBackendLoginProvider implements LoginProviderInterface
{
    /**
     * Login provider identifier registered in ext_localconf.php (`?loginProvider=...`).
     */
    public const string IDENTIFIER = '1744276800';

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
        $queryParams = $request->getQueryParams();

        $loginUrl = PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendLoginPath());
        $redirect = MixedCaster::string($queryParams['redirect'] ?? null);
        if ($redirect !== '') {
            $loginUrl = PathUtility::appendQueryParameters($loginUrl, ['returnTo' => $redirect]);
        }

        if ($view instanceof FluidViewAdapter) {
            $templatePaths = $view->getRenderingContext()->getTemplatePaths();
            $templatePaths->setTemplateRootPaths([...$templatePaths->getTemplateRootPaths(), 'EXT:workos_auth/Resources/Private/Templates']);
            $templatePaths->setPartialRootPaths([...$templatePaths->getPartialRootPaths(), 'EXT:workos_auth/Resources/Private/Partials']);
        }

        $authError = MixedCaster::string($queryParams['workosAuthError'] ?? null);
        $endpoint = static fn(string $path): string => PathUtility::joinBaseAndPath($backendBasePath, $path);

        $magicAuthState = trim(MixedCaster::string($queryParams['magicAuthState'] ?? null));
        $magicAuthEmail = '';
        if ($magicAuthState !== '') {
            try {
                $payload = $this->stateService->peek($request, BackendWorkosAuthMiddleware::MAGIC_AUTH_CONTEXT, $magicAuthState);
                $magicAuthEmail = MixedCaster::string($payload['email'] ?? null);
            } catch (\RuntimeException) {
                $authError = $authError !== '' ? $authError : $this->translator->translate('error.invalidMagicAuthSession');
            }
            if ($magicAuthEmail === '') {
                $magicAuthState = '';
            }
        }

        $emailVerificationState = trim(MixedCaster::string($queryParams['emailVerificationState'] ?? null));
        $emailVerificationEmail = '';
        $emailVerificationCanResend = false;
        if ($emailVerificationState !== '') {
            try {
                $payload = $this->stateService->peek($request, BackendWorkosAuthMiddleware::EMAIL_VERIFICATION_CONTEXT, $emailVerificationState);
                $emailVerificationEmail = MixedCaster::string($payload['email'] ?? null);
                $emailVerificationCanResend = MixedCaster::string($payload['userId'] ?? null) !== '';
                if (MixedCaster::string($payload['pendingToken'] ?? null) === '') {
                    $emailVerificationEmail = '';
                }
            } catch (\RuntimeException) {
                $authError = $authError !== '' ? $authError : $this->translator->translate('error.verificationSessionExpired');
            }
            if ($emailVerificationEmail === '') {
                $emailVerificationState = '';
                $emailVerificationCanResend = false;
            }
        }

        $this->pageRenderer->addCssFile('EXT:workos_auth/Resources/Public/Css/Backend/login-provider.css');
        if ($this->configuration->isBackendReady()) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@webconsulting/workos-auth/workos-login.js')
            );
        }

        $view->assignMultiple([
            'enabled' => $this->configuration->isBackendEnabled(),
            'configured' => $this->configuration->isBackendReady(),
            'loginUrl' => $loginUrl,
            'backToLoginUrl' => PathUtility::appendQueryParameters($endpoint('/login'), ['loginProvider' => self::IDENTIFIER]),
            'setupUrl' => $endpoint('/module/workos/setup'),
            'passwordAuthUrl' => $endpoint(BackendWorkosAuthMiddleware::PASSWORD_AUTH_PATH),
            'magicSendUrl' => $endpoint(BackendWorkosAuthMiddleware::MAGIC_AUTH_SEND_PATH),
            'magicVerifyUrl' => $endpoint(BackendWorkosAuthMiddleware::MAGIC_AUTH_VERIFY_PATH),
            'magicAuthState' => $magicAuthState,
            'magicAuthEmail' => $magicAuthEmail,
            'emailVerifyUrl' => $endpoint(BackendWorkosAuthMiddleware::EMAIL_VERIFY_PATH),
            'emailVerifyResendUrl' => $endpoint(BackendWorkosAuthMiddleware::EMAIL_VERIFY_RESEND_PATH),
            'emailVerificationState' => $emailVerificationState,
            'emailVerificationEmail' => $emailVerificationEmail,
            'emailVerificationCanResend' => $emailVerificationCanResend,
            'socialProviders' => array_map(fn(SocialProvider $provider): array => [
                'key' => $provider->value,
                'label' => $this->translator->translate($provider->labelKey()),
                'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => $provider->value]),
            ], SocialProvider::cases()),
            'authError' => $authError,
            'authErrorDetails' => $this->buildAuthErrorDetails($authError, $endpoint('/module/workos/setup')),
            'authNotice' => MixedCaster::string($queryParams['workosAuthNotice'] ?? null),
            'backendCookieSameSite' => $this->configuration->getBackendCookieSameSite(),
            'backendCookieSameSiteCompatible' => $this->configuration->isBackendCookieSameSiteCompatible(),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(RequestTokenService::BACKEND_LOGIN_SCOPE),
        ]);

        return 'Login/WorkosLoginProvider';
    }

    /**
     * Turn the "not linked / provisioning disabled" message of
     * UserProvisioningService into an actionable error card.
     *
     * @return array<string, string|bool>|null
     */
    private function buildAuthErrorDetails(string $rawMessage, string $setupUrl): ?array
    {
        $rawMessage = trim($rawMessage);
        if ($rawMessage === '') {
            return null;
        }

        $notLinked = preg_match(
            '/No backend user matched the WorkOS account \(email "([^"]*)", id "([^"]*)"\) and automatic backend provisioning is disabled\./i',
            $rawMessage,
            $matches
        ) === 1;

        if (!$notLinked) {
            return [
                'title' => $this->translator->translate('backend.login.error.title'),
                'summary' => $rawMessage,
                'email' => '',
                'userId' => '',
                'hint' => '',
                'actionUrl' => '',
                'actionLabel' => '',
                'isProvisioningDisabled' => false,
            ];
        }

        return [
            'title' => $this->translator->translate('backend.login.error.notLinked.title'),
            'summary' => $this->translator->translate('backend.login.error.notLinked.summary', ['email' => $matches[1]]),
            'email' => $matches[1],
            'userId' => $matches[2],
            'hint' => $this->translator->translate('backend.login.error.notLinked.hint'),
            'actionUrl' => $setupUrl,
            'actionLabel' => $this->translator->translate('backend.login.error.notLinked.action'),
            'isProvisioningDisabled' => true,
        ];
    }
}
