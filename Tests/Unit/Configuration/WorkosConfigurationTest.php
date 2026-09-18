<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;
use Webconsulting\WorkosAuth\Service\LabelTranslator;

final class WorkosConfigurationTest extends TestCase
{
    private WorkosConfiguration $configuration;
    private mixed $originalBackendCookieSameSite;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBackendCookieSameSite = $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] ?? null;
        $this->configuration = $this->createConfigurationWith([]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalBackendCookieSameSite === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = $this->originalBackendCookieSameSite;
        }
        parent::tearDown();
    }

    public function testDefaultsAreAppliedForMissingKeys(): void
    {
        $settings = $this->configuration->all();

        self::assertSame('', $settings['apiKey']);
        self::assertTrue($settings['frontendEnabled']);
        self::assertFalse($settings['backendAutoCreateUsers']);
        self::assertSame('/workos-auth/frontend/login', $settings['frontendLoginPath']);
        self::assertSame('/main', $settings['backendSuccessPath']);
        self::assertSame(McpAuthenticationMode::Auto, $this->configuration->getMcpAuthenticationMode());
        self::assertSame(10, $settings['mcpServerLimit']);
        self::assertFalse($this->configuration->hasWorkosCredentials());
        self::assertFalse($this->configuration->isFrontendReady());
        self::assertFalse($this->configuration->isBackendReady());
    }

    public function testNormalizeInputTrimsStringsAndNormalizesPaths(): void
    {
        $result = $this->configuration->normalizeInput([
            'apiKey' => '  sk_test_abc ',
            'clientId' => '  client_abc ',
            'frontendEnabled' => false,
            'frontendLoginPath' => 'workos-auth/login',
            'frontendCallbackPath' => '/workos-auth/callback/',
            'frontendLogoutPath' => '',
            'frontendSuccessRedirect' => '  ',
            'frontendStoragePid' => '-1',
        ]);

        self::assertSame('sk_test_abc', $result['apiKey']);
        self::assertSame('client_abc', $result['clientId']);
        self::assertFalse($result['frontendEnabled']);
        self::assertSame('/workos-auth/login', $result['frontendLoginPath']);
        self::assertSame('/workos-auth/callback', $result['frontendCallbackPath']);
        self::assertSame('/', $result['frontendLogoutPath']);
        self::assertSame('/', $result['frontendSuccessRedirect']);
        self::assertSame(0, $result['frontendStoragePid']);
    }

    public function testNormalizeInputNormalizesMcpSettings(): void
    {
        $result = $this->configuration->normalizeInput([
            'mcpEnabled' => true,
            'mcpServerPath' => 'custom-mcp/',
            'mcpAuthenticationMode' => 'WORKOS',
            'mcpAuthkitDomain' => ' https://example.authkit.app/ ',
            'mcpWorkosDiscovery' => true,
            'mcpServerLimit' => 25,
            'mcpVerboseLogging' => true,
        ]);

        self::assertTrue($result['mcpEnabled']);
        self::assertSame('/custom-mcp', $result['mcpServerPath']);
        self::assertSame('workos', $result['mcpAuthenticationMode']);
        self::assertSame('https://example.authkit.app', $result['mcpAuthkitDomain']);
        self::assertTrue($result['mcpWorkosDiscovery']);
        self::assertSame(10, $result['mcpServerLimit']);
        self::assertTrue($result['mcpVerboseLogging']);
    }

    public function testNormalizeInputFallsBackToAutoForUnknownMcpMode(): void
    {
        self::assertSame('auto', $this->configuration->normalizeInput(['mcpAuthenticationMode' => 'bogus'])['mcpAuthenticationMode']);
        self::assertSame(1, $this->configuration->normalizeInput(['mcpServerLimit' => '0'])['mcpServerLimit']);
    }

    public function testNormalizeInputNormalizesWidgetCorsOrigins(): void
    {
        $result = $this->configuration->normalizeInput([
            'widgetCorsAutoRegister' => true,
            'widgetCorsOrigins' => ' https://Example.test/foo, http://app.test:8080/path; ftp://invalid https://example.test:443 ',
        ]);

        self::assertTrue($result['widgetCorsAutoRegister']);
        self::assertSame('https://example.test,http://app.test:8080', $result['widgetCorsOrigins']);
    }

    public function testGettersExposeParsedLists(): void
    {
        $configuration = $this->createConfigurationWith([
            'widgetCorsAutoRegister' => '0',
            'widgetCorsOrigins' => 'https://Example.test/foo;http://app.test:8080/path;not-an-origin',
            'frontendDefaultGroupUids' => '3, 0; abc 7',
            'backendAllowedDomains' => 'Example.com, partner.org',
            'authkitOrganizationId' => '  ',
        ]);

        self::assertFalse($configuration->shouldAutoRegisterWidgetCorsOrigins());
        self::assertSame(['https://example.test', 'http://app.test:8080'], $configuration->getWidgetCorsOrigins());
        self::assertSame([3, 7], $configuration->getFrontendDefaultGroupUids());
        self::assertSame(['example.com', 'partner.org'], $configuration->getBackendAllowedDomains());
        self::assertNull($configuration->getAuthkitOrganizationId());
    }

    public function testValidateReportsMissingCredentialsWhenAuthEnabled(): void
    {
        $errors = $this->configuration->validate($this->configuration->normalizeInput([
            'frontendEnabled' => true,
            'backendEnabled' => false,
        ]));

        self::assertArrayHasKey('apiKey', $errors);
        self::assertArrayHasKey('clientId', $errors);
    }

    public function testValidateAcceptsDisabledWithoutSecrets(): void
    {
        $errors = $this->configuration->validate($this->configuration->normalizeInput([
            'frontendEnabled' => false,
            'backendEnabled' => false,
            'mcpAuthenticationMode' => 'anonymous',
        ]));

        self::assertSame([], $errors);
    }

    public function testValidateRequiresStoragePidAndBackendGroupsForAutoCreate(): void
    {
        $errors = $this->configuration->validate($this->configuration->normalizeInput([
            'apiKey' => 'sk_test_abc',
            'clientId' => 'client_abc',
            'frontendAutoCreateUsers' => true,
            'frontendStoragePid' => 0,
            'backendAutoCreateUsers' => true,
            'backendDefaultGroupUids' => '',
            'mcpEnabled' => false,
        ]));

        self::assertSame(['frontendStoragePid', 'backendDefaultGroupUids'], array_keys($errors));
    }

    public function testValidateRequiresAuthkitDomainWhenMcpAlwaysRequiresWorkos(): void
    {
        $errors = $this->configuration->validate($this->configuration->normalizeInput([
            'frontendEnabled' => false,
            'backendEnabled' => false,
            'mcpEnabled' => true,
            'mcpAuthenticationMode' => 'workos',
            'mcpAuthkitDomain' => '',
        ]));

        self::assertSame(['mcpAuthkitDomain'], array_keys($errors));
    }

    public function testBackendCookieSameSiteCompatibilityAcceptsCoreValues(): void
    {
        foreach (['strict', 'lax', 'none', 'LAX'] as $value) {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = $value;
            self::assertTrue($this->configuration->isBackendCookieSameSiteCompatible(), $value);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite']);
        self::assertSame('strict', $this->configuration->getBackendCookieSameSite());
    }

    public function testValidateReportsUnsupportedBackendCookieSameSiteValue(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'unsupported';

        $errors = $this->configuration->validate($this->configuration->normalizeInput([
            'frontendEnabled' => false,
            'backendEnabled' => true,
            'apiKey' => 'sk_test_abc',
            'clientId' => 'client_abc',
            'mcpEnabled' => false,
        ]));

        self::assertSame(['backendCookieSameSite'], array_keys($errors));
        self::assertFalse($this->configuration->isBackendReady());
    }

    public function testSavePersistsSettingsFlushesSystemCachesAndUpdatesInMemoryState(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $cacheManager = $this->createMock(CacheManager::class);
        $configuration = new WorkosConfiguration(
            $extensionConfiguration,
            $cacheManager,
            new LabelTranslator(self::createStub(LanguageServiceFactory::class)),
        );
        $settings = $configuration->normalizeInput(['apiKey' => 'sk_test_saved', 'clientId' => 'client_saved']);

        $extensionConfiguration->expects(self::once())->method('set')->with(WorkosConfiguration::EXTENSION_KEY, $settings);
        $cacheManager->expects(self::once())->method('flushCachesInGroup')->with('system');

        $configuration->save($settings);

        self::assertSame('sk_test_saved', $configuration->getApiKey());
        self::assertTrue($configuration->hasWorkosCredentials());
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     */
    private function createConfigurationWith(array $rawConfiguration): WorkosConfiguration
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($rawConfiguration);

        return new WorkosConfiguration(
            $extensionConfiguration,
            self::createStub(CacheManager::class),
            new LabelTranslator(self::createStub(LanguageServiceFactory::class)),
        );
    }
}
