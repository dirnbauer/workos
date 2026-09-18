<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Service\ExtensionSchemaService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\RequestBody;

/**
 * Backend module "WorkOS > MCP Server": the MCP subset of the extension
 * configuration, endpoint URLs per site and the database schema status.
 *
 * @phpstan-import-type WorkosSettings from WorkosConfiguration
 */
#[Autoconfigure(public: true)]
final readonly class McpConfigurationController
{
    private const string REQUEST_TOKEN_SCOPE = 'workos/backend/mcp';

    /**
     * @var list<string>
     */
    private const MCP_SETTINGS = [
        'mcpEnabled',
        'mcpServerPath',
        'mcpAuthenticationMode',
        'mcpAuthkitDomain',
        'mcpWorkosDiscovery',
        'mcpServerLimit',
        'mcpVerboseLogging',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private WorkosConfiguration $configuration,
        private RequestTokenService $requestTokenService,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private SiteFinder $siteFinder,
        private LabelTranslator $translator,
        private ExtensionSchemaService $extensionSchemaService,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->configuration->all();
        $mode = $this->configuration->getMcpAuthenticationMode();
        $workosRequired = $this->configuration->mcpRequiresWorkos();

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'formValues' => $settings,
            'errors' => $this->mcpErrors($settings),
            'saveUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_mcp.save'),
            'schemaUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_mcp.schema'),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(self::REQUEST_TOKEN_SCOPE),
            'status' => [
                'context' => (string)Environment::getContext(),
                'enabled' => $settings['mcpEnabled'],
                'workosRequired' => $workosRequired,
                'ready' => $settings['mcpEnabled'] && (!$workosRequired || $settings['mcpAuthkitDomain'] !== ''),
            ],
            'databaseSchema' => $this->extensionSchemaService->getStatus(),
            'endpointUrls' => $this->buildEndpointUrls($request, $settings['mcpServerPath']),
            'modeOptions' => array_map(fn(McpAuthenticationMode $option): array => [
                'value' => $option->value,
                'label' => $this->translator->translate($option->labelKey()),
                'description' => $this->translator->translate($option->descriptionKey()),
                'selected' => $option === $mode,
            ], McpAuthenticationMode::cases()),
        ]);
        $moduleTemplate->setTitle($this->translator->translate('module.mcp.title'));

        return $moduleTemplate->renderResponse('Backend/McpConfiguration/Index');
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->requestTokenService->validate(self::REQUEST_TOKEN_SCOPE)) {
            $this->flash($this->translator->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        // Only the MCP fields are part of this form; unchecked checkboxes are absent from the body.
        $input = RequestBody::fromRequest($request)->group('configuration');
        $mcpInput = [];
        foreach (self::MCP_SETTINGS as $key) {
            $mcpInput[$key] = $input[$key] ?? ($key === 'mcpAuthenticationMode' ? McpAuthenticationMode::Auto->value : '');
        }
        $settings = $this->configuration->normalizeInput(array_replace($this->configuration->all(), $mcpInput));
        $errors = $this->mcpErrors($settings);

        try {
            $this->configuration->save($settings);
        } catch (\Throwable $exception) {
            $this->flash($this->translator->translate('flash.configSaveError', ['error' => $exception->getMessage()]), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        if ($errors !== []) {
            $this->flash($this->translator->translate('module.mcp.flash.savedWithWarnings', ['errors' => implode(' ', $errors)]), ContextualFeedbackSeverity::WARNING);
        } else {
            $this->flash($this->translator->translate('module.mcp.flash.saved'), ContextualFeedbackSeverity::OK);
        }

        return $this->redirectToIndex();
    }

    public function applySchemaAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->requestTokenService->validate(self::REQUEST_TOKEN_SCOPE)) {
            $this->flash($this->translator->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            $result = $this->extensionSchemaService->applyPendingUpdates();
        } catch (\Throwable $exception) {
            $this->flash($this->translator->translate('module.mcp.schema.flash.error', ['error' => $exception->getMessage()]), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        if ($result['errors'] !== []) {
            $this->flash(
                $this->translator->translate('module.mcp.schema.flash.partial', [
                    'count' => $result['appliedCount'],
                    'errors' => implode(' ', $result['errors']),
                ]),
                ContextualFeedbackSeverity::WARNING,
            );
        } elseif ($result['appliedCount'] === 0) {
            $this->flash($this->translator->translate('module.mcp.schema.flash.upToDate'), ContextualFeedbackSeverity::OK);
        } else {
            $this->flash($this->translator->translate('module.mcp.schema.flash.applied', ['count' => $result['appliedCount']]), ContextualFeedbackSeverity::OK);
        }

        return $this->redirectToIndex();
    }

    /**
     * @return list<array{label: string, endpoint: string, protectedResource: string, authorizationServer: string}>
     */
    private function buildEndpointUrls(ServerRequestInterface $request, string $mcpServerPath): array
    {
        $urls = [[
            'label' => $this->translator->translate('module.mcp.endpoints.currentHost'),
            'endpoint' => PathUtility::buildAbsoluteUrlFromRequest($request, $mcpServerPath),
            'protectedResource' => PathUtility::buildAbsoluteUrlFromRequest($request, WorkosConfiguration::MCP_PROTECTED_RESOURCE_METADATA_PATH),
            'authorizationServer' => PathUtility::buildAbsoluteUrlFromRequest($request, WorkosConfiguration::MCP_AUTHORIZATION_SERVER_METADATA_PATH),
        ]];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $baseUrl = PathUtility::siteBaseUrl($site, $request);
            $urls[] = [
                'label' => $site->getIdentifier(),
                'endpoint' => PathUtility::joinBaseUrlAndPath($baseUrl, $mcpServerPath),
                'protectedResource' => PathUtility::joinBaseUrlAndPath($baseUrl, WorkosConfiguration::MCP_PROTECTED_RESOURCE_METADATA_PATH),
                'authorizationServer' => PathUtility::joinBaseUrlAndPath($baseUrl, WorkosConfiguration::MCP_AUTHORIZATION_SERVER_METADATA_PATH),
            ];
        }

        return $urls;
    }

    /**
     * @param WorkosSettings $settings
     * @return array<string, string>
     */
    private function mcpErrors(array $settings): array
    {
        return array_filter(
            $this->configuration->validate($settings),
            static fn(string $key): bool => str_starts_with($key, 'mcp'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier('workos-auth-mcp')
            ->addMessage(new FlashMessage($message, $this->translator->translate('module.mcp.flashTitle'), $severity, true));
    }

    private function redirectToIndex(): ResponseInterface
    {
        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_mcp'));
    }
}
