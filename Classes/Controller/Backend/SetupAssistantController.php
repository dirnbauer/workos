<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
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
 * Backend module "WorkOS > Setup": credentials, frontend and backend sign-in
 * and the hosted-login options, plus the redirect URIs to register in
 * WorkOS. The MCP server has a module of its own.
 */
#[Autoconfigure(public: true)]
final readonly class SetupAssistantController
{
    public const string FORM_ID = 'workos-setup';

    private const string REQUEST_TOKEN_SCOPE = 'workos/backend/setup';

    public function __construct(
        private ModulePageFactory $modulePageFactory,
        private WorkosConfiguration $configuration,
        private SiteFinder $siteFinder,
        private RequestTokenService $requestTokenService,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private LabelTranslator $translator,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->configuration->all();

        // Redirect URIs WorkOS must know: one for the backend, one per site.
        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $redirectUris = [[
            'label' => $this->translator->translate('setup.redirectUrls.backend'),
            'url' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, $settings['backendCallbackPath'])
            ),
        ]];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $redirectUris[] = [
                'label' => $site->getIdentifier(),
                'url' => PathUtility::joinBaseUrlAndPath(PathUtility::siteBaseUrl($site, $request), $settings['frontendCallbackPath']),
            ];
        }

        $errors = array_filter(
            $this->configuration->validate($settings),
            static fn(string $key): bool => !str_starts_with($key, 'mcp'),
            ARRAY_FILTER_USE_KEY
        );

        $view = $this->modulePageFactory->create($request, 'workos_setup', 'setup.title', self::FORM_ID);
        $view->assignMultiple([
            'formId' => self::FORM_ID,
            // The API key is write-only: the page shows whether one is
            // stored, never the key itself.
            'formValues' => ['apiKey' => ''] + $settings,
            'apiKeyStored' => $settings['apiKey'] !== '',
            'apiKeyHint' => self::maskSecret($settings['apiKey']),
            'errors' => $errors,
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(self::REQUEST_TOKEN_SCOPE),
            'saveUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup.save'),
            'mcpUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_mcp'),
            'redirectUris' => $redirectUris,
            'allRedirectUris' => implode("\n", array_column($redirectUris, 'url')),
            'backendCookieSameSite' => $this->configuration->getBackendCookieSameSite(),
            'backendCookieSameSiteCompatible' => $this->configuration->isBackendCookieSameSiteCompatible(),
        ]);
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');

        return $view->renderResponse('Backend/SetupAssistant/Index');
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->requestTokenService->validate(self::REQUEST_TOKEN_SCOPE)) {
            $this->flash($this->translator->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $current = $this->configuration->all();
        $submitted = RequestBody::fromRequest($request)->group('configuration');
        // Settings this form does not show (the MCP server's) keep their values.
        $submitted = array_intersect_key($submitted, $current);
        if (trim(is_string($submitted['apiKey'] ?? null) ? $submitted['apiKey'] : '') === '') {
            unset($submitted['apiKey']);
        }
        $settings = $this->configuration->normalizeInput(array_replace($current, $submitted));
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

    /**
     * "sk_…a1b2" for a stored key, '' when there is none.
     */
    private static function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        $prefix = str_contains($secret, '_') ? strstr($secret, '_', true) . '_' : '';

        return $prefix . '…' . substr($secret, -4);
    }

    private function flash(string $body, ContextualFeedbackSeverity $severity): void
    {
        $this->modulePageFactory->flash($body, $severity, $this->translator->translate('setup.flashTitle'));
    }

    private function redirectToIndex(): ResponseInterface
    {
        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_setup'));
    }
}
