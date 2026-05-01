<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Mcp;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\MixedCaster;

final class McpJsonRpcService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PROTOCOL_VERSION = '2025-11-25';

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosMcpRegistryService $registryService,
    ) {}

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, McpRequestContext $context): ?array
    {
        $id = $message['id'] ?? null;
        $method = MixedCaster::string($message['method'] ?? null);

        if ($method === '') {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }

        if ($this->configuration->shouldLogMcpVerbously()) {
            $this->logger?->info(sprintf(
                'TYPO3 MCP method "%s" called by %s.',
                $method,
                $context->workosUserId ?? 'anonymous'
            ));
        }

        if (!array_key_exists('id', $message)) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->success($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => [
                        'listChanged' => false,
                    ],
                ],
                'serverInfo' => [
                    'name' => 'typo3-workos-auth',
                    'title' => 'TYPO3 WorkOS MCP',
                    'version' => '0.26.0',
                ],
                'instructions' => 'This TYPO3 MCP endpoint exposes the current TYPO3/WorkOS identity context and WorkOS-authorized MCP applications for the authenticated WorkOS user.',
            ]),
            'ping' => $this->success($id, new \stdClass()),
            'tools/list' => $this->success($id, ['tools' => $this->tools()]),
            'tools/call' => $this->callTool($id, is_array($message['params'] ?? null) ? $message['params'] : [], $context),
            default => $this->error($id, -32601, sprintf('Unsupported MCP method "%s".', $method)),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'workos.mcp_context',
                'title' => 'TYPO3 WorkOS identity context',
                'description' => 'Shows whether the MCP request is anonymous or WorkOS-authenticated and which TYPO3 frontend/backend user and groups are linked.',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'workos.authorized_mcp_servers',
                'title' => 'Authorized WorkOS MCP applications',
                'description' => 'Lists up to the configured limit of WorkOS Connect applications the current WorkOS user has authorized.',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ],
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
            'workos.mcp_context' => $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($context->toPublicArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'structuredContent' => $context->toPublicArray(),
            ]),
            'workos.authorized_mcp_servers' => $this->authorizedServers($id, $context),
            default => $this->error($id, -32602, sprintf('Unknown tool "%s".', $name)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizedServers(mixed $id, McpRequestContext $context): array
    {
        $servers = $this->registryService->listAuthorizedServers($context);
        $payload = [
            'servers' => $servers,
            'limit' => $this->configuration->getMcpServerLimit(),
            'workosDiscoveryEnabled' => $this->configuration->shouldDiscoverWorkosMcpServers(),
            'requiresWorkosUser' => true,
        ];

        return $this->success($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'structuredContent' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function success(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
