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

    protected function setUp(): void
    {
        parent::setUp();

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $this->configuration = new WorkosConfiguration(
            $extensionConfiguration,
            self::createStub(LanguageServiceFactory::class),
        );
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
            'frontendLoginPath' => 'workos-auth/login',
            'frontendCallbackPath' => '/workos-auth/callback/',
            'frontendLogoutPath' => '',
            'frontendSuccessRedirect' => '',
            'backendAllowedDomains' => '',
            'frontendStoragePid' => '-1',
        ]);

        self::assertSame('sk_test_abc', $result['apiKey']);
        self::assertSame('/workos-auth/login', $result['frontendLoginPath']);
        self::assertSame('/workos-auth/callback', $result['frontendCallbackPath']);
        self::assertSame('/', $result['frontendLogoutPath']);
        self::assertSame('/', $result['frontendSuccessRedirect']);
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
}
