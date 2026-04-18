<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class SetupAssistantController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly WorkosConfiguration $configuration,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteFinder $siteFinder,
        private readonly FormProtectionFactory $formProtectionFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly FlashMessageService $flashMessageService,
        private readonly CacheManager $cacheManager,
        private readonly PageRenderer $pageRenderer,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $formValues = $this->configuration->all();

        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $backendUrls = [
            'login' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendLoginPath'])
            ),
            'callback' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendCallbackPath'])
            ),
            'success' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendSuccessPath'])
            ),
        ];

        $frontendSites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $baseUrl = rtrim((string)$site->getBase(), '/');
            $frontendSites[] = [
                'identifier' => $site->getIdentifier(),
                'baseUrl' => $baseUrl,
                'loginUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendLoginPath']),
                'callbackUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendCallbackPath']),
                'logoutUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendLogoutPath']),
            ];
        }

        $moduleTemplate->assignMultiple([
            'formValues' => $formValues,
            'csrfToken' => $this->generateToken(),
            'saveUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup.save'),
            'backendUrls' => $backendUrls,
            'frontendSites' => $frontendSites,
            'hasCredentials' => trim((string)$formValues['apiKey']) !== '' && trim((string)$formValues['clientId']) !== '',
            'cookiePasswordValid' => mb_strlen(trim((string)$formValues['cookiePassword'])) >= 32,
        ]);
        $moduleTemplate->setTitle($this->translate('setup.title'));
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/workos-auth/copy-urls.js');

        return $moduleTemplate->renderResponse('Backend/SetupAssistant/Index');
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $payload = is_array($parsedBody) ? $parsedBody : [];
        $formValues = $this->configuration->normalizeInput(
            is_array($payload['configuration'] ?? null) ? $payload['configuration'] : []
        );

        if (($payload['generateCookiePassword'] ?? '') === '1') {
            $formValues['cookiePassword'] = $this->generateCookiePassword();
        }

        if (!$this->isValidToken((string)($payload['csrfToken'] ?? ''))) {
            $this->enqueueFlashMessage(
                $this->translate('error.csrfTokenInvalid'),
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_setup'));
        }

        $errors = $this->configuration->validate($formValues);

        try {
            $this->extensionConfiguration->set(WorkosConfiguration::EXTENSION_KEY, $formValues);
            $this->cacheManager->flushCachesInGroup('system');
        } catch (\Throwable $e) {
            $this->enqueueFlashMessage(
                $this->translate('flash.configSaveError', ['error' => $e->getMessage()]),
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_setup'));
        }

        if ($errors !== []) {
            $this->enqueueFlashMessage(
                $this->translate('flash.configSavedNotReady', ['errors' => implode(' ', $errors)]),
                ContextualFeedbackSeverity::WARNING,
            );
        } else {
            $this->enqueueFlashMessage(
                $this->translate('flash.configSaved'),
                ContextualFeedbackSeverity::OK,
            );
        }

        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_setup'));
    }

    private function enqueueFlashMessage(string $body, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier('workos-auth-setup')
            ->addMessage(new FlashMessage($body, $this->translate('setup.flashTitle'), $severity, true));
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

    private function generateCookiePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function generateToken(): string
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->generateToken('workosAuth', 'saveConfiguration');
    }

    private function isValidToken(string $token): bool
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->validateToken($token, 'workosAuth', 'saveConfiguration');
    }
}
