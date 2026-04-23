<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;

final class WorkosConfigurationTest extends TestCase
{
    private WorkosConfiguration $configuration;
    private string|null $originalBackendCookieSameSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBackendCookieSameSite = $this->readBackendCookieSameSite();

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $this->configuration = new WorkosConfiguration(
            $extensionConfiguration,
            self::createStub(LanguageServiceFactory::class),
        );
    }

    protected function tearDown(): void
    {
        if ($this->originalBackendCookieSameSite === null) {
            $this->removeBackendCookieSameSite();
        } else {
            $this->writeBackendCookieSameSite($this->originalBackendCookieSameSite);
        }

        parent::tearDown();
    }

    public function testSupportedSocialProvidersContainsExpectedSet(): void
    {
        self::assertContains('GoogleOAuth', WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS);
        self::assertContains('MicrosoftOAuth', WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS);
        self::assertContains('GitHubOAuth', WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS);
        self::assertContains('AppleOAuth', WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS);
        self::assertCount(4, WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS);
    }

    public function testNormalizeInputTrimsStringsAndNormalizesPaths(): void
    {
        $result = $this->configuration->normalizeInput([
            'apiKey' => '  sk_test_abc ',
            'clientId' => '  client_abc ',
            'cookiePassword' => '  12345678901234567890123456789012  ',
            'frontendEnabled' => false,
            'frontendLoginPath' => 'workos-auth/login',
            'frontendCallbackPath' => '/workos-auth/callback/',
            'frontendLogoutPath' => '',
            'frontendSuccessRedirect' => '  /dashboard  ',
            'backendAllowedDomains' => '',
            'frontendStoragePid' => '-1',
        ]);

        self::assertSame('sk_test_abc', $result['apiKey']);
        self::assertSame('client_abc', $result['clientId']);
        self::assertSame('12345678901234567890123456789012', $result['cookiePassword']);
        self::assertFalse($result['frontendEnabled']);
        self::assertSame('/workos-auth/login', $result['frontendLoginPath']);
        self::assertSame('/workos-auth/callback', $result['frontendCallbackPath']);
        self::assertSame('/', $result['frontendLogoutPath']);
        self::assertSame('/dashboard', $result['frontendSuccessRedirect']);
        self::assertSame(0, $result['frontendStoragePid']);
    }

    public function testValidateReportsMissingCredentialsWhenAuthEnabled(): void
    {
        $errors = $this->configuration->validate([
            'frontendEnabled' => true,
            'backendEnabled' => false,
            'apiKey' => '',
            'clientId' => '',
            'cookiePassword' => str_repeat('x', 32),
        ]);

        self::assertArrayHasKey('apiKey', $errors);
        self::assertArrayHasKey('clientId', $errors);
        self::assertArrayNotHasKey('cookiePassword', $errors);
    }

    public function testValidateAcceptsDisabledWithoutSecrets(): void
    {
        $errors = $this->configuration->validate([
            'frontendEnabled' => false,
            'backendEnabled' => false,
        ]);

        self::assertSame([], $errors);
    }

    public function testValidateRequiresCookiePasswordOfAtLeast32Characters(): void
    {
        $errors = $this->configuration->validate([
            'frontendEnabled' => true,
            'apiKey' => 'sk_test_abc',
            'clientId' => 'client_abc',
            'cookiePassword' => 'short',
        ]);

        self::assertArrayHasKey('cookiePassword', $errors);
    }

    public function testBackendCookieSameSiteCompatibilityRequiresLaxOrNone(): void
    {
        $this->writeBackendCookieSameSite('strict');
        self::assertFalse($this->configuration->isBackendCookieSameSiteCompatible());

        $this->writeBackendCookieSameSite('lax');
        self::assertTrue($this->configuration->isBackendCookieSameSiteCompatible());

        $this->writeBackendCookieSameSite('none');
        self::assertTrue($this->configuration->isBackendCookieSameSiteCompatible());
    }

    public function testValidateReportsBackendCookieSameSiteMismatch(): void
    {
        $this->writeBackendCookieSameSite('strict');

        $errors = $this->configuration->validate([
            'frontendEnabled' => false,
            'backendEnabled' => true,
            'apiKey' => 'sk_test_abc',
            'clientId' => 'client_abc',
            'cookiePassword' => str_repeat('x', 32),
            'backendAutoCreateUsers' => false,
        ]);

        self::assertArrayHasKey('backendCookieSameSite', $errors);
    }

    private function readBackendCookieSameSite(): string|null
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            return null;
        }

        $beConfiguration = $confVars['BE'] ?? null;
        if (!is_array($beConfiguration)) {
            return null;
        }

        $cookieSameSite = $beConfiguration['cookieSameSite'] ?? null;
        return is_string($cookieSameSite) ? $cookieSameSite : null;
    }

    private function writeBackendCookieSameSite(string $value): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $stringKeyedConfVars = is_array($confVars) ? $confVars : [];
        $beConfiguration = is_array($stringKeyedConfVars['BE'] ?? null) ? $stringKeyedConfVars['BE'] : [];
        $beConfiguration['cookieSameSite'] = $value;
        $stringKeyedConfVars['BE'] = $beConfiguration;
        $GLOBALS['TYPO3_CONF_VARS'] = $stringKeyedConfVars;
    }

    private function removeBackendCookieSameSite(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            return;
        }

        $beConfiguration = $confVars['BE'] ?? null;
        if (!is_array($beConfiguration)) {
            return;
        }

        unset($beConfiguration['cookieSameSite']);
        $confVars['BE'] = $beConfiguration;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }
}
