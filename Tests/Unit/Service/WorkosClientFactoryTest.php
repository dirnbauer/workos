<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\WorkosClientFactory;

final class WorkosClientFactoryTest extends TestCase
{
    public function testClientRequiresCredentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277900);

        (new WorkosClientFactory($this->createConfiguration([])))->client();
    }

    public function testEveryCallBuildsAFreshClientFromTheConfiguredCredentials(): void
    {
        $factory = new WorkosClientFactory($this->createConfiguration(['apiKey' => 'sk_test_abc', 'clientId' => 'client_abc']));

        self::assertNotSame($factory->client(), $factory->client(), 'credential changes must apply without a cache flush');
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     */
    private function createConfiguration(array $rawConfiguration): WorkosConfiguration
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
