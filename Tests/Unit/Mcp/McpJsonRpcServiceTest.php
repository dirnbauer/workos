<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;
use Webconsulting\WorkosAuth\Mcp\McpJsonRpcService;
use Webconsulting\WorkosAuth\Mcp\McpRequestContext;
use Webconsulting\WorkosAuth\Mcp\WorkosMcpRegistryService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\WorkosClientFactory;

final class McpJsonRpcServiceTest extends TestCase
{
    public function testInitializeReturnsMcpCapabilities(): void
    {
        $response = $this->createService()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25'],
        ], $this->createContext());

        self::assertIsArray($response);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertIsArray($response['result']);
        self::assertSame(McpJsonRpcService::PROTOCOL_VERSION, $response['result']['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false]], $response['result']['capabilities']);
    }

    public function testPingReturnsAnEmptyObject(): void
    {
        $response = $this->createService()->handle(['jsonrpc' => '2.0', 'id' => 'p', 'method' => 'ping'], $this->createContext());

        self::assertIsArray($response);
        self::assertEquals(new \stdClass(), $response['result']);
    }

    public function testNotificationsWithoutIdProduceNoResponse(): void
    {
        self::assertNull($this->createService()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->createContext()));
    }

    public function testMissingMethodIsAnInvalidRequest(): void
    {
        $response = $this->createService()->handle(['jsonrpc' => '2.0', 'id' => 3], $this->createContext());

        self::assertSame(['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32600, 'message' => 'Invalid JSON-RPC request.']], $response);
    }

    public function testUnknownMethodAndUnknownToolAreReported(): void
    {
        $service = $this->createService();

        $unknownMethod = $service->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list'], $this->createContext());
        self::assertIsArray($unknownMethod);
        self::assertSame(-32601, $unknownMethod['error']['code'] ?? null);

        $unknownTool = $service->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nope']], $this->createContext());
        self::assertIsArray($unknownTool);
        self::assertSame(-32602, $unknownTool['error']['code'] ?? null);
    }

    public function testToolsListContainsWorkosIntrospectionTools(): void
    {
        $response = $this->createService()->handle(['jsonrpc' => '2.0', 'id' => 'tools', 'method' => 'tools/list'], $this->createContext());

        self::assertIsArray($response);
        self::assertIsArray($response['result']);
        self::assertIsArray($response['result']['tools']);
        self::assertSame(['workos.mcp_context', 'workos.authorized_mcp_servers'], array_column($response['result']['tools'], 'name'));
    }

    public function testContextToolReturnsTypo3UserAndGroupMapping(): void
    {
        $response = $this->createService()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'workos.mcp_context', 'arguments' => []],
        ], $this->createContext());

        self::assertIsArray($response);
        self::assertIsArray($response['result']);
        $structuredContent = $response['result']['structuredContent'];
        self::assertIsArray($structuredContent);
        self::assertSame('workos', $structuredContent['authenticationMode']);
        self::assertSame('user_123', $structuredContent['workosUserId']);
        self::assertSame(['uid' => 10, 'groupUids' => [1, 2]], $structuredContent['frontendUser']);
        self::assertSame(['uid' => 20, 'groupUids' => [3]], $structuredContent['backendUser']);
        self::assertIsArray($response['result']['content']);
        self::assertSame('text', $response['result']['content'][0]['type'] ?? null);
    }

    public function testAuthorizedServersToolReturnsAnEmptyListWhenDiscoveryIsOff(): void
    {
        $response = $this->createService()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'workos.authorized_mcp_servers'],
        ], $this->createContext());

        self::assertIsArray($response);
        self::assertIsArray($response['result']);
        self::assertSame(
            ['servers' => [], 'limit' => 10, 'workosDiscoveryEnabled' => false, 'requiresWorkosUser' => true],
            $response['result']['structuredContent']
        );
    }

    private function createService(): McpJsonRpcService
    {
        $configuration = $this->createConfiguration();

        return new McpJsonRpcService($configuration, new WorkosMcpRegistryService($configuration, new WorkosClientFactory($configuration)));
    }

    private function createConfiguration(): WorkosConfiguration
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'frontendEnabled' => false,
            'backendEnabled' => false,
            'mcpWorkosDiscovery' => false,
        ]);

        return new WorkosConfiguration(
            $extensionConfiguration,
            self::createStub(CacheManager::class),
            new LabelTranslator(self::createStub(LanguageServiceFactory::class)),
        );
    }

    private function createContext(): McpRequestContext
    {
        return new McpRequestContext(
            authenticationMode: McpAuthenticationMode::Workos,
            workosRequired: true,
            workosUserId: 'user_123',
            email: 'user@example.com',
            frontendUserUid: 10,
            frontendGroupUids: [1, 2],
            backendUserUid: 20,
            backendGroupUids: [3],
            claims: ['sub' => 'user_123'],
        );
    }
}
