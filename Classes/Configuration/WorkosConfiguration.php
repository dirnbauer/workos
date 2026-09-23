<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Configuration;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;

/**
 * Typed access to the `EXTENSIONS.workos_auth` extension configuration.
 *
 * @phpstan-type WorkosSettings array{
 *     apiKey: string,
 *     clientId: string,
 *     frontendEnabled: bool,
 *     frontendAutoCreateUsers: bool,
 *     frontendLinkByEmail: bool,
 *     frontendStoragePid: int,
 *     frontendDefaultGroupUids: string,
 *     frontendLoginPath: string,
 *     frontendCallbackPath: string,
 *     frontendLogoutPath: string,
 *     frontendSuccessRedirect: string,
 *     backendEnabled: bool,
 *     backendAutoCreateUsers: bool,
 *     backendLinkByEmail: bool,
 *     backendDefaultGroupUids: string,
 *     backendAllowedDomains: string,
 *     backendLoginPath: string,
 *     backendCallbackPath: string,
 *     backendSuccessPath: string,
 *     widgetCorsAutoRegister: bool,
 *     widgetCorsOrigins: string,
 *     authkitOrganizationId: string,
 *     authkitConnectionId: string,
 *     authkitDomainHint: string,
 *     mcpEnabled: bool,
 *     mcpServerPath: string,
 *     mcpAuthenticationMode: string,
 *     mcpAuthkitDomain: string,
 *     mcpWorkosDiscovery: bool,
 *     mcpServerLimit: int,
 *     mcpVerboseLogging: bool,
 * }
 */
final class WorkosConfiguration
{
    public const string EXTENSION_KEY = 'workos_auth';
    public const string MCP_PROTECTED_RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';
    public const string MCP_AUTHORIZATION_SERVER_METADATA_PATH = '/.well-known/oauth-authorization-server';
    public const int MCP_SERVER_LIMIT_MAX = 10;

    /**
     * @var WorkosSettings
     */
    private const array DEFAULTS = [
        'apiKey' => '',
        'clientId' => '',
        'frontendEnabled' => true,
        'frontendAutoCreateUsers' => true,
        'frontendLinkByEmail' => true,
        'frontendStoragePid' => 0,
        'frontendDefaultGroupUids' => '',
        'frontendLoginPath' => '/workos-auth/frontend/login',
        'frontendCallbackPath' => '/workos-auth/frontend/callback',
        'frontendLogoutPath' => '/workos-auth/frontend/logout',
        'frontendSuccessRedirect' => '/',
        'backendEnabled' => true,
        'backendAutoCreateUsers' => false,
        'backendLinkByEmail' => true,
        'backendDefaultGroupUids' => '',
        'backendAllowedDomains' => '',
        'backendLoginPath' => '/workos-auth/backend/login',
        'backendCallbackPath' => '/workos-auth/backend/callback',
        'backendSuccessPath' => '/main',
        'widgetCorsAutoRegister' => true,
        'widgetCorsOrigins' => '',
        'authkitOrganizationId' => '',
        'authkitConnectionId' => '',
        'authkitDomainHint' => '',
        'mcpEnabled' => true,
        'mcpServerPath' => '/workos-auth/mcp',
        'mcpAuthenticationMode' => 'auto',
        'mcpAuthkitDomain' => '',
        'mcpWorkosDiscovery' => true,
        'mcpServerLimit' => self::MCP_SERVER_LIMIT_MAX,
        'mcpVerboseLogging' => false,
    ];

    /**
     * @var WorkosSettings|null
     */
    private ?array $configuration = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CacheManager $cacheManager,
        private readonly LabelTranslator $translator,
    ) {}

    /**
     * @return WorkosSettings
     */
    public function all(): array
    {
        return $this->configuration ??= $this->normalizeInput(
            MixedCaster::stringKeyedArray($this->readRawConfiguration()) ?? []
        );
    }

    /**
     * Persist normalized settings and flush the caches TYPO3 derives from them.
     *
     * @param WorkosSettings $settings
     */
    public function save(array $settings): void
    {
        $this->extensionConfiguration->set(self::EXTENSION_KEY, $settings);
        $this->cacheManager->flushCachesInGroup('system');
        $this->configuration = $settings;
    }

    /**
     * @param array<string, mixed> $input
     * @return WorkosSettings
     */
    public function normalizeInput(array $input): array
    {
        $string = static fn(string $key): string => trim(MixedCaster::string($input[$key] ?? self::DEFAULTS[$key]));
        $bool = static fn(string $key): bool => (bool)($input[$key] ?? self::DEFAULTS[$key]);
        $int = static fn(string $key): int => MixedCaster::int($input[$key] ?? self::DEFAULTS[$key]);
        $path = static fn(string $key): string => PathUtility::normalizePath($string($key));

        $successRedirect = $string('frontendSuccessRedirect');

        return [
            'apiKey' => $string('apiKey'),
            'clientId' => $string('clientId'),
            'frontendEnabled' => $bool('frontendEnabled'),
            'frontendAutoCreateUsers' => $bool('frontendAutoCreateUsers'),
            'frontendLinkByEmail' => $bool('frontendLinkByEmail'),
            'frontendStoragePid' => max(0, $int('frontendStoragePid')),
            'frontendDefaultGroupUids' => $string('frontendDefaultGroupUids'),
            'frontendLoginPath' => $path('frontendLoginPath'),
            'frontendCallbackPath' => $path('frontendCallbackPath'),
            'frontendLogoutPath' => $path('frontendLogoutPath'),
            'frontendSuccessRedirect' => $successRedirect !== '' ? $successRedirect : '/',
            'backendEnabled' => $bool('backendEnabled'),
            'backendAutoCreateUsers' => $bool('backendAutoCreateUsers'),
            'backendLinkByEmail' => $bool('backendLinkByEmail'),
            'backendDefaultGroupUids' => $string('backendDefaultGroupUids'),
            'backendAllowedDomains' => $string('backendAllowedDomains'),
            'backendLoginPath' => $path('backendLoginPath'),
            'backendCallbackPath' => $path('backendCallbackPath'),
            'backendSuccessPath' => $path('backendSuccessPath'),
            'widgetCorsAutoRegister' => $bool('widgetCorsAutoRegister'),
            'widgetCorsOrigins' => implode(',', self::parseOriginList($string('widgetCorsOrigins'))),
            'authkitOrganizationId' => $string('authkitOrganizationId'),
            'authkitConnectionId' => $string('authkitConnectionId'),
            'authkitDomainHint' => $string('authkitDomainHint'),
            'mcpEnabled' => $bool('mcpEnabled'),
            'mcpServerPath' => $path('mcpServerPath'),
            'mcpAuthenticationMode' => (McpAuthenticationMode::tryFrom(strtolower($string('mcpAuthenticationMode'))) ?? McpAuthenticationMode::Auto)->value,
            'mcpAuthkitDomain' => rtrim($string('mcpAuthkitDomain'), '/'),
            'mcpWorkosDiscovery' => $bool('mcpWorkosDiscovery'),
            'mcpServerLimit' => min(self::MCP_SERVER_LIMIT_MAX, max(1, $int('mcpServerLimit'))),
            'mcpVerboseLogging' => $bool('mcpVerboseLogging'),
        ];
    }

    /**
     * Validate normalized settings. Returns translated messages keyed by setting.
     *
     * @param WorkosSettings $settings
     * @return array<string, string>
     */
    public function validate(array $settings): array
    {
        $errors = [];
        $authEnabled = $settings['frontendEnabled'] || $settings['backendEnabled'];

        if ($authEnabled && $settings['apiKey'] === '') {
            $errors['apiKey'] = $this->translator->translate('validation.apiKeyRequired');
        }
        if ($authEnabled && $settings['clientId'] === '') {
            $errors['clientId'] = $this->translator->translate('validation.clientIdRequired');
        }
        if ($settings['frontendEnabled'] && $settings['frontendAutoCreateUsers'] && $settings['frontendStoragePid'] <= 0) {
            $errors['frontendStoragePid'] = $this->translator->translate('validation.frontendStoragePidRequired');
        }
        if ($settings['backendEnabled'] && $settings['backendAutoCreateUsers'] && $settings['backendDefaultGroupUids'] === '') {
            $errors['backendDefaultGroupUids'] = $this->translator->translate('validation.backendGroupUidsRequired');
        }
        if ($settings['backendEnabled'] && !$this->isBackendCookieSameSiteCompatible()) {
            $errors['backendCookieSameSite'] = $this->translator->translate(
                'validation.backendCookieSameSiteUnsupported',
                ['sameSite' => $this->getBackendCookieSameSite()]
            );
        }
        if ($settings['mcpEnabled']
            && $this->mcpRequiresWorkos(McpAuthenticationMode::from($settings['mcpAuthenticationMode']))
            && $settings['mcpAuthkitDomain'] === ''
        ) {
            $errors['mcpAuthkitDomain'] = $this->translator->translate('validation.mcpAuthkitDomainRequired');
        }

        return $errors;
    }

    public function hasWorkosCredentials(): bool
    {
        return $this->getApiKey() !== '' && $this->getClientId() !== '';
    }

    public function getApiKey(): string
    {
        return $this->all()['apiKey'];
    }

    public function getClientId(): string
    {
        return $this->all()['clientId'];
    }

    public function isFrontendEnabled(): bool
    {
        return $this->all()['frontendEnabled'];
    }

    public function isFrontendReady(): bool
    {
        $settings = $this->all();

        return $settings['frontendEnabled']
            && $this->hasWorkosCredentials()
            && (!$settings['frontendAutoCreateUsers'] || $settings['frontendStoragePid'] > 0);
    }

    public function shouldAutoCreateFrontendUsers(): bool
    {
        return $this->all()['frontendAutoCreateUsers'];
    }

    public function shouldLinkFrontendUsersByEmail(): bool
    {
        return $this->all()['frontendLinkByEmail'];
    }

    public function getFrontendStoragePid(): int
    {
        return $this->all()['frontendStoragePid'];
    }

    /**
     * @return list<int>
     */
    public function getFrontendDefaultGroupUids(): array
    {
        return self::parseIntegerList($this->all()['frontendDefaultGroupUids']);
    }

    public function getFrontendLoginPath(): string
    {
        return $this->all()['frontendLoginPath'];
    }

    public function getFrontendCallbackPath(): string
    {
        return $this->all()['frontendCallbackPath'];
    }

    public function getFrontendLogoutPath(): string
    {
        return $this->all()['frontendLogoutPath'];
    }

    public function getFrontendSuccessRedirect(): string
    {
        return $this->all()['frontendSuccessRedirect'];
    }

    public function isBackendEnabled(): bool
    {
        return $this->all()['backendEnabled'];
    }

    public function isBackendReady(): bool
    {
        $settings = $this->all();

        return $settings['backendEnabled']
            && $this->hasWorkosCredentials()
            && $this->isBackendCookieSameSiteCompatible()
            && (!$settings['backendAutoCreateUsers'] || $settings['backendDefaultGroupUids'] !== '');
    }

    public function getBackendCookieSameSite(): string
    {
        $value = strtolower(trim(MixedCaster::string($GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] ?? null)));

        return $value !== '' ? $value : 'strict';
    }

    public function isBackendCookieSameSiteCompatible(): bool
    {
        return in_array($this->getBackendCookieSameSite(), ['strict', 'lax', 'none'], true);
    }

    public function shouldAutoCreateBackendUsers(): bool
    {
        return $this->all()['backendAutoCreateUsers'];
    }

    public function shouldLinkBackendUsersByEmail(): bool
    {
        return $this->all()['backendLinkByEmail'];
    }

    /**
     * @return list<int>
     */
    public function getBackendDefaultGroupUids(): array
    {
        return self::parseIntegerList($this->all()['backendDefaultGroupUids']);
    }

    /**
     * @return list<string>
     */
    public function getBackendAllowedDomains(): array
    {
        return self::splitList(strtolower($this->all()['backendAllowedDomains']));
    }

    public function getBackendLoginPath(): string
    {
        return $this->all()['backendLoginPath'];
    }

    public function getBackendCallbackPath(): string
    {
        return $this->all()['backendCallbackPath'];
    }

    public function getBackendSuccessPath(): string
    {
        return $this->all()['backendSuccessPath'];
    }

    public function shouldAutoRegisterWidgetCorsOrigins(): bool
    {
        return $this->all()['widgetCorsAutoRegister'];
    }

    /**
     * @return list<string>
     */
    public function getWidgetCorsOrigins(): array
    {
        return self::parseOriginList($this->all()['widgetCorsOrigins']);
    }

    public function getAuthkitOrganizationId(): ?string
    {
        return self::nullIfEmpty($this->all()['authkitOrganizationId']);
    }

    public function getAuthkitConnectionId(): ?string
    {
        return self::nullIfEmpty($this->all()['authkitConnectionId']);
    }

    public function getAuthkitDomainHint(): ?string
    {
        return self::nullIfEmpty($this->all()['authkitDomainHint']);
    }

    public function isMcpEnabled(): bool
    {
        return $this->all()['mcpEnabled'];
    }

    public function getMcpServerPath(): string
    {
        return $this->all()['mcpServerPath'];
    }

    public function getMcpAuthenticationMode(): McpAuthenticationMode
    {
        return McpAuthenticationMode::from($this->all()['mcpAuthenticationMode']);
    }

    /**
     * Whether MCP requests must carry a WorkOS bearer token, taking the TYPO3
     * application context into account for the `auto` mode.
     */
    public function mcpRequiresWorkos(?McpAuthenticationMode $mode = null): bool
    {
        return ($mode ?? $this->getMcpAuthenticationMode())->requiresWorkos(Environment::getContext()->isProduction());
    }

    public function getMcpAuthkitDomain(): ?string
    {
        return self::nullIfEmpty($this->all()['mcpAuthkitDomain']);
    }

    public function shouldDiscoverWorkosMcpServers(): bool
    {
        return $this->all()['mcpWorkosDiscovery'];
    }

    public function getMcpServerLimit(): int
    {
        return $this->all()['mcpServerLimit'];
    }

    public function shouldLogMcpVerbosely(): bool
    {
        return $this->all()['mcpVerboseLogging'];
    }

    private function readRawConfiguration(): mixed
    {
        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    /**
     * Split a comma / whitespace / semicolon separated list.
     *
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        $items = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $items === false ? [] : $items;
    }

    /**
     * @return list<int>
     */
    private static function parseIntegerList(string $value): array
    {
        return array_values(array_filter(
            array_map(intval(...), self::splitList($value)),
            static fn(int $item): bool => $item > 0
        ));
    }

    /**
     * @return list<string>
     */
    private static function parseOriginList(string $value): array
    {
        $origins = [];
        foreach (self::splitList($value) as $item) {
            $origin = PathUtility::normalizeOrigin($item);
            if ($origin !== '') {
                $origins[$origin] = $origin;
            }
        }

        return array_values($origins);
    }
}
