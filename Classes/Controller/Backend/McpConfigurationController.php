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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\MixedCaster;
use WebConsulting\WorkosAuth\Security\RequestTokenService;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class McpConfigurationController
{
    private const REQUEST_TOKEN_SCOPE = 'workos/backend/mcp';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly WorkosConfiguration $configuration,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RequestTokenService $requestTokenService,
        private readonly UriBuilder $uriBuilder,
        private readonly FlashMessageService $flashMessageService,
        private readonly CacheManager $cacheManager,
        private readonly SiteFinder $siteFinder,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $formValues = $this->configuration->all();
        $mcpErrors = $this->filterMcpErrors($this->configuration->validate($formValues));

        $moduleTemplate->assignMultiple([
            'formValues' => $formValues,
            'errors' => $mcpErrors,
            'saveUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_mcp.save'),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'requestTokenValue' => $this->requestTokenService->createHashed(self::REQUEST_TOKEN_SCOPE),
            'status' => $this->buildStatus($formValues),
            'endpointUrls' => $this->buildEndpointUrls($request, $formValues),
            'modeOptions' => $this->buildModeOptions(MixedCaster::string($formValues['mcpAuthenticationMode'] ?? null)),
        ]);
        $moduleTemplate->setTitle($this->translate('module.mcp.title'));

        return $moduleTemplate->renderResponse('Backend/McpConfiguration/Index');
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->requestTokenService->validate(self::REQUEST_TOKEN_SCOPE)) {
            $this->flash($this->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $parsedBody = $request->getParsedBody();
        $payload = is_array($parsedBody) ? $parsedBody : [];
        $configurationInput = $payload['configuration'] ?? null;
        $mcpInput = [];
        if (is_array($configurationInput)) {
            foreach ($configurationInput as $key => $value) {
                $mcpInput[(string)$key] = $value;
            }
        }

        $formValues = $this->configuration->normalizeInput(array_replace($this->configuration->all(), [
            'mcpEnabled' => $mcpInput['mcpEnabled'] ?? '0',
            'mcpServerPath' => $mcpInput['mcpServerPath'] ?? '',
            'mcpAuthenticationMode' => $mcpInput['mcpAuthenticationMode'] ?? WorkosConfiguration::MCP_AUTHENTICATION_AUTO,
            'mcpAuthkitDomain' => $mcpInput['mcpAuthkitDomain'] ?? '',
            'mcpWorkosDiscovery' => $mcpInput['mcpWorkosDiscovery'] ?? '0',
            'mcpServerLimit' => $mcpInput['mcpServerLimit'] ?? '10',
            'mcpVerboseLogging' => $mcpInput['mcpVerboseLogging'] ?? '0',
        ]));

        $mcpErrors = $this->filterMcpErrors($this->configuration->validate($formValues));

        try {
            $this->extensionConfiguration->set(WorkosConfiguration::EXTENSION_KEY, $formValues);
            $this->cacheManager->flushCachesInGroup('system');
        } catch (\Throwable $exception) {
            $this->flash(
                $this->translate('flash.configSaveError', ['error' => $exception->getMessage()]),
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectToIndex();
        }

        if ($mcpErrors !== []) {
            $this->flash(
                $this->translate('module.mcp.flash.savedWithWarnings', ['errors' => implode(' ', $mcpErrors)]),
                ContextualFeedbackSeverity::WARNING,
            );
        } else {
            $this->flash($this->translate('module.mcp.flash.saved'), ContextualFeedbackSeverity::OK);
        }

        return $this->redirectToIndex();
    }

    /**
     * @param array<string, mixed> $formValues
     * @return array<string, mixed>
     */
    private function buildStatus(array $formValues): array
    {
        $mode = MixedCaster::string($formValues['mcpAuthenticationMode'] ?? null);
        $isProduction = Environment::getContext()->isProduction();
        $workosRequired = $mode === WorkosConfiguration::MCP_AUTHENTICATION_WORKOS
            || ($mode === WorkosConfiguration::MCP_AUTHENTICATION_AUTO && $isProduction);
        $authkitDomain = trim(MixedCaster::string($formValues['mcpAuthkitDomain'] ?? null));

        return [
            'context' => (string)Environment::getContext(),
            'enabled' => (bool)($formValues['mcpEnabled'] ?? false),
            'workosRequired' => $workosRequired,
            'ready' => (bool)($formValues['mcpEnabled'] ?? false) && (!$workosRequired || $authkitDomain !== ''),
            'authkitConfigured' => $authkitDomain !== '',
            'authkitDomain' => $authkitDomain,
            'discoveryEnabled' => (bool)($formValues['mcpWorkosDiscovery'] ?? false),
            'limit' => MixedCaster::int($formValues['mcpServerLimit'] ?? null),
            'verboseLogging' => (bool)($formValues['mcpVerboseLogging'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $formValues
     * @return list<array{label: string, endpoint: string, protectedResource: string, authorizationServer: string}>
     */
    private function buildEndpointUrls(ServerRequestInterface $request, array $formValues): array
    {
        $urls = [[
            'label' => $this->translate('module.mcp.endpoints.currentHost'),
            'endpoint' => PathUtility::buildAbsoluteUrlFromRequest($request, MixedCaster::string($formValues['mcpServerPath'] ?? null)),
            'protectedResource' => PathUtility::buildAbsoluteUrlFromRequest($request, $this->configuration->getMcpProtectedResourceMetadataPath()),
            'authorizationServer' => PathUtility::buildAbsoluteUrlFromRequest($request, $this->configuration->getMcpAuthorizationServerMetadataPath()),
        ]];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $siteBase = $site->getBase();
            if ($siteBase->getHost() !== '') {
                $baseUrl = rtrim((string)$siteBase, '/');
            } else {
                $baseUrl = rtrim(PathUtility::buildAbsoluteUrlFromRequest($request, $siteBase->getPath()), '/');
            }
            $urls[] = [
                'label' => $site->getIdentifier(),
                'endpoint' => PathUtility::joinBaseUrlAndPath($baseUrl, MixedCaster::string($formValues['mcpServerPath'] ?? null)),
                'protectedResource' => PathUtility::joinBaseUrlAndPath($baseUrl, $this->configuration->getMcpProtectedResourceMetadataPath()),
                'authorizationServer' => PathUtility::joinBaseUrlAndPath($baseUrl, $this->configuration->getMcpAuthorizationServerMetadataPath()),
            ];
        }

        return $urls;
    }

    /**
     * @return list<array{value: string, label: string, description: string, selected: bool}>
     */
    private function buildModeOptions(string $selectedMode): array
    {
        return [
            [
                'value' => WorkosConfiguration::MCP_AUTHENTICATION_AUTO,
                'label' => $this->translate('setup.mcp.authenticationMode.auto'),
                'description' => $this->translate('module.mcp.mode.auto.description'),
                'selected' => $selectedMode === WorkosConfiguration::MCP_AUTHENTICATION_AUTO,
            ],
            [
                'value' => WorkosConfiguration::MCP_AUTHENTICATION_WORKOS,
                'label' => $this->translate('setup.mcp.authenticationMode.workos'),
                'description' => $this->translate('module.mcp.mode.workos.description'),
                'selected' => $selectedMode === WorkosConfiguration::MCP_AUTHENTICATION_WORKOS,
            ],
            [
                'value' => WorkosConfiguration::MCP_AUTHENTICATION_ANONYMOUS,
                'label' => $this->translate('setup.mcp.authenticationMode.anonymous'),
                'description' => $this->translate('module.mcp.mode.anonymous.description'),
                'selected' => $selectedMode === WorkosConfiguration::MCP_AUTHENTICATION_ANONYMOUS,
            ],
        ];
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function filterMcpErrors(array $errors): array
    {
        return array_filter($errors, static fn(string $key): bool => str_starts_with($key, 'mcp'), ARRAY_FILTER_USE_KEY);
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier('workos-auth-mcp')
            ->addMessage(new FlashMessage($message, $this->translate('module.mcp.flashTitle'), $severity, true));
    }

    private function redirectToIndex(): ResponseInterface
    {
        return new RedirectResponse($this->uriBuilder->buildUriFromRoute('workos_mcp'));
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
}
