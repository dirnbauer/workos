<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\RequestBody;

/**
 * Backend module "WorkOS > Setup Assistant": edits the whole extension
 * configuration and lists the redirect URIs to register in WorkOS.
 */
#[Autoconfigure(public: true)]
final readonly class SetupAssistantController
{
    private const string REQUEST_TOKEN_SCOPE = 'workos/backend/setup';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private WorkosConfiguration $configuration,
        private SiteFinder $siteFinder,
        private RequestTokenService $requestTokenService,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private PageRenderer $pageRenderer,
        private LabelTranslator $translator,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->configuration->all();

        // Redirect URIs WorkOS must know: one for the backend, one per site.
        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $backendCallbackUrl = PathUtility::buildAbsoluteUrlFromRequest(
            $request,
            PathUtility::joinBaseAndPath($backendBasePath, $settings['backendCallbackPath'])
        );
        $frontendSites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $frontendSites[] = [
                'identifier' => $site->getIdentifier(),
                'callbackUrl' => PathUtility::joinBaseUrlAndPath(PathUtility::siteBaseUrl($site, $request), $settings['frontendCallbackPath']),
            ];
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'formValues' => $settings,
            'errors' => $this->configuration->validate($settings),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(self::REQUEST_TOKEN_SCOPE),
            'saveUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup.save'),
            'backendCallbackUrl' => $backendCallbackUrl,
            'frontendSites' => $frontendSites,
            'backendCookieSameSite' => $this->configuration->getBackendCookieSameSite(),
            'backendCookieSameSiteCompatible' => $this->configuration->isBackendCookieSameSiteCompatible(),
        ]);
        $moduleTemplate->setTitle($this->translator->translate('setup.title'));
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/workos-auth/copy-urls.js');

        return $moduleTemplate->renderResponse('Backend/SetupAssistant/Index');
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->requestTokenService->validate(self::REQUEST_TOKEN_SCOPE)) {
            $this->flash($this->translator->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $settings = $this->configuration->normalizeInput(RequestBody::fromRequest($request)->group('configuration'));
        $errors = $this->configuration->validate($settings);

        try {
            $this->configuration->save($settings);
        } catch (\Throwable $e) {
            $this->flash($this->translator->translate('flash.configSaveError', ['error' => $e->getMessage()]), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        if ($errors !== []) {
            $this->flash($this->translator->translate('flash.configSavedNotReady', ['errors' => implode(' ', $errors)]), ContextualFeedbackSeverity::WARNING);
        } else {
            $this->flash($this->translator->translate('flash.configSaved'), ContextualFeedbackSeverity::OK);
        }

        return $this->redirectToIndex();
    }

    private function flash(string $body, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier('workos-auth-setup')
            ->addMessage(new FlashMessage($body, $this->translator->translate('setup.flashTitle'), $severity, true));
    }

    private function redirectToIndex(): ResponseInterface
    {
        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_setup'));
    }
}
