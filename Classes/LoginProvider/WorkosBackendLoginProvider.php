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

        $message = $this->takeLoginMessage($request, MixedCaster::string($queryParams[BackendWorkosAuthMiddleware::LOGIN_MESSAGE_PARAMETER] ?? null));
        $authError = $message['error'];
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
                'label' => $this->translator->translate('backend.login.continueWith', [
                    'provider' => $this->translator->translate($provider->labelKey()),
                ]),
                'url' => PathUtility::appendQueryParameters($loginUrl, ['provider' => $provider->value]),
            ], SocialProvider::cases()),
            'authError' => $authError,
            'notLinked' => $message['details'],
            'authNotice' => $message['notice'],
            'backendCookieSameSite' => $this->configuration->getBackendCookieSameSite(),
            'backendCookieSameSiteCompatible' => $this->configuration->isBackendCookieSameSiteCompatible(),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(RequestTokenService::BACKEND_LOGIN_SCOPE),
        ]);

        return 'Login/WorkosLoginProvider';
    }

    /**
     * The message the login middleware stored for this browser, shown once.
     * An unknown, foreign or expired token shows nothing: the login page
     * never displays text taken from its URL.
     *
     * @return array{error: string, notice: string, details: array{email: string, userId: string}|null}
     */
    private function takeLoginMessage(ServerRequestInterface $request, string $token): array
    {
        $message = ['error' => '', 'notice' => '', 'details' => null];
        if (trim($token) === '') {
            return $message;
        }

        try {
            $payload = $this->stateService->consume($request, BackendWorkosAuthMiddleware::LOGIN_MESSAGE_CONTEXT, trim($token));
        } catch (\RuntimeException) {
            return $message;
        }

        $details = MixedCaster::stringKeyedArray($payload['details'] ?? null) ?? [];
        $email = MixedCaster::string($details['email'] ?? null);

        return [
            'error' => MixedCaster::string($payload['error'] ?? null),
            'notice' => MixedCaster::string($payload['notice'] ?? null),
            'details' => $email !== '' ? ['email' => $email, 'userId' => MixedCaster::string($details['userId'] ?? null)] : null,
        ];
    }
}
