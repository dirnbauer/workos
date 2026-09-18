<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * JSON-RPC 2.0 dispatcher for the MCP methods TYPO3 supports:
 * initialize, ping, tools/list and tools/call.
 */
final class McpJsonRpcService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const string PROTOCOL_VERSION = '2025-11-25';

    private const string TOOL_CONTEXT = 'workos.mcp_context';
    private const string TOOL_AUTHORIZED_SERVERS = 'workos.authorized_mcp_servers';

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosMcpRegistryService $registryService,
    ) {}

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null null for notifications (messages without an id)
     */
    public function handle(array $message, McpRequestContext $context): ?array
    {
        $id = $message['id'] ?? null;
        $method = MixedCaster::string($message['method'] ?? null);

        if ($method === '') {
            return self::error($id, -32600, 'Invalid JSON-RPC request.');
        }

        if ($this->configuration->shouldLogMcpVerbosely()) {
            $this->logger?->info(sprintf('TYPO3 MCP method "%s" called by %s.', $method, $context->workosUserId ?? 'anonymous'));
        }

        if (!array_key_exists('id', $message)) {
            return null;
        }

        return match ($method) {
            'initialize' => self::success($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'typo3-workos-auth', 'title' => 'TYPO3 WorkOS MCP', 'version' => '1.0.0'],
                'instructions' => 'This TYPO3 MCP endpoint exposes the current TYPO3/WorkOS identity context and WorkOS-authorized MCP applications for the authenticated WorkOS user.',
            ]),
            'ping' => self::success($id, new \stdClass()),
            'tools/list' => self::success($id, ['tools' => self::tools()]),
            'tools/call' => $this->callTool($id, MixedCaster::stringKeyedArray($message['params'] ?? null) ?? [], $context),
            default => self::error($id, -32601, sprintf('Unsupported MCP method "%s".', $method)),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function tools(): array
    {
        $noArguments = ['type' => 'object', 'additionalProperties' => false];

        return [
            [
                'name' => self::TOOL_CONTEXT,
                'title' => 'TYPO3 WorkOS identity context',
                'description' => 'Shows whether the MCP request is anonymous or WorkOS-authenticated and which TYPO3 frontend/backend user and groups are linked.',
                'inputSchema' => $noArguments,
            ],
            [
                'name' => self::TOOL_AUTHORIZED_SERVERS,
                'title' => 'Authorized WorkOS MCP applications',
                'description' => 'Lists up to the configured limit of WorkOS Connect applications the current WorkOS user has authorized.',
                'inputSchema' => $noArguments,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, McpRequestContext $context): array
    {
        $name = MixedCaster::string($params['name'] ?? null);

        return match ($name) {
            self::TOOL_CONTEXT => self::toolResult($id, $context->toPublicArray()),
            self::TOOL_AUTHORIZED_SERVERS => self::toolResult($id, [
                'servers' => $this->registryService->listAuthorizedServers($context),
                'limit' => $this->configuration->getMcpServerLimit(),
                'workosDiscoveryEnabled' => $this->configuration->shouldDiscoverWorkosMcpServers(),
                'requiresWorkosUser' => true,
            ]),
            default => self::error($id, -32602, sprintf('Unknown tool "%s".', $name)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function toolResult(mixed $id, array $payload): array
    {
        return self::success($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function success(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
