<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Mcp\McpJsonRpcService;
use WebConsulting\WorkosAuth\Mcp\McpRequestContext;
use WebConsulting\WorkosAuth\Mcp\WorkosMcpRegistryService;
use WebConsulting\WorkosAuth\Service\WorkosClientFactory;

final class McpJsonRpcServiceTest extends TestCase
{
    public function testInitializeReturnsMcpCapabilities(): void
    {
        $service = $this->createService();

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
            ],
        ], $this->createContext());

        self::assertIsArray($response);
        $result = $this->arrayFromMixed($response['result'] ?? null);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertSame('2025-11-25', $result['protocolVersion'] ?? null);
        self::assertArrayHasKey('tools', $this->arrayFromMixed($result['capabilities'] ?? null));
    }

    public function testToolsListContainsWorkosIntrospectionTools(): void
    {
        $service = $this->createService();

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 'tools',
            'method' => 'tools/list',
        ], $this->createContext());

        self::assertIsArray($response);
        $result = $this->arrayFromMixed($response['result'] ?? null);
        $tools = $this->listOfArraysFromMixed($result['tools'] ?? null);
        $toolNames = array_column($tools, 'name');
        self::assertContains('workos.mcp_context', $toolNames);
        self::assertContains('workos.authorized_mcp_servers', $toolNames);
    }

    public function testContextToolReturnsTypo3UserAndGroupMapping(): void
    {
        $service = $this->createService();
        $context = $this->createContext();

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'workos.mcp_context',
                'arguments' => [],
            ],
        ], $context);

        self::assertIsArray($response);
        $result = $this->arrayFromMixed($response['result'] ?? null);
        $structuredContent = $this->arrayFromMixed($result['structuredContent'] ?? null);
        $frontendUser = $this->arrayFromMixed($structuredContent['frontendUser'] ?? null);
        $backendUser = $this->arrayFromMixed($structuredContent['backendUser'] ?? null);
        self::assertSame('user_123', $structuredContent['workosUserId'] ?? null);
        self::assertSame([1, 2], $frontendUser['groupUids'] ?? null);
        self::assertSame([3], $backendUser['groupUids'] ?? null);
    }

    private function createService(): McpJsonRpcService
    {
        $configuration = $this->createConfiguration();
        $registry = new WorkosMcpRegistryService(
            $configuration,
            new WorkosClientFactory($configuration),
        );

        return new McpJsonRpcService($configuration, $registry);
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
            self::createStub(LanguageServiceFactory::class),
        );
    }

    private function createContext(): McpRequestContext
    {
        return new McpRequestContext(
            authenticationMode: WorkosConfiguration::MCP_AUTHENTICATION_WORKOS,
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

    /**
     * @return array<string, mixed>
     */
    private function arrayFromMixed(mixed $value): array
    {
        self::assertIsArray($value);
        $array = [];
        foreach ($value as $key => $item) {
            $array[(string)$key] = $item;
        }
        return $array;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArraysFromMixed(mixed $value): array
    {
        self::assertIsArray($value);
        $list = [];
        foreach ($value as $item) {
            $list[] = $this->arrayFromMixed($item);
        }
        return $list;
    }
}
