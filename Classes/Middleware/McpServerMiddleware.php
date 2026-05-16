<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Mcp\McpAuthenticationException;
use WebConsulting\WorkosAuth\Mcp\McpAuthenticationService;
use WebConsulting\WorkosAuth\Mcp\McpJsonRpcService;
use WebConsulting\WorkosAuth\Mcp\McpRequestContext;
use WebConsulting\WorkosAuth\Security\SecretRedactor;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class McpServerMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly McpAuthenticationService $authenticationService,
        private readonly McpJsonRpcService $jsonRpcService,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->configuration->isMcpEnabled()) {
            return $handler->handle($request);
        }

        $relativePath = $this->getRelativePath($request);
        if ($relativePath === $this->configuration->getMcpProtectedResourceMetadataPath()) {
            return $this->protectedResourceMetadataResponse($request);
        }
        if ($relativePath === $this->configuration->getMcpAuthorizationServerMetadataPath()) {
            return $this->authorizationServerMetadataResponse();
        }
        if ($relativePath !== $this->configuration->getMcpServerPath()) {
            return $handler->handle($request);
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->withMcpProtocolHeader(new JsonResponse(
                ['error' => 'The TYPO3 MCP endpoint expects JSON-RPC requests via POST.'],
                405,
                ['Allow' => 'POST']
            ));
        }

        try {
            $context = $this->authenticationService->authenticate($request);
        } catch (McpAuthenticationException $exception) {
            $this->logger?->warning('TYPO3 MCP authentication failed: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->unauthorizedResponse($request, $exception->getMessage());
        }

        try {
            return $this->withMcpProtocolHeader($this->handleJsonRpc($request, $context));
        } catch (\Throwable $exception) {
            $this->logger?->error('TYPO3 MCP request failed: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->withMcpProtocolHeader(new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal MCP server error.',
                ],
            ], 500));
        }
    }

    private function handleJsonRpc(ServerRequestInterface $request, McpRequestContext $context): ResponseInterface
    {
        $rawBody = trim((string)$request->getBody());
        if ($rawBody === '') {
            return new JsonResponse($this->jsonRpcError(null, -32700, 'Empty JSON-RPC request body.'), 400);
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse($this->jsonRpcError(null, -32700, 'Invalid JSON request body.'), 400);
        }

        if (is_array($decoded) && $this->isList($decoded)) {
            $responses = [];
            foreach ($decoded as $message) {
                $jsonRpcMessage = $this->normalizeJsonObject($message);
                if ($jsonRpcMessage === null) {
                    $responses[] = $this->jsonRpcError(null, -32600, 'Invalid JSON-RPC request.');
                    continue;
                }
                $response = $this->jsonRpcService->handle($jsonRpcMessage, $context);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }
            return $responses === [] ? new Response(null, 202) : new JsonResponse($responses);
        }

        $jsonRpcMessage = $this->normalizeJsonObject($decoded);
        if ($jsonRpcMessage === null) {
            return new JsonResponse($this->jsonRpcError(null, -32600, 'Invalid JSON-RPC request.'), 400);
        }

        $response = $this->jsonRpcService->handle($jsonRpcMessage, $context);
        return $response === null ? new Response(null, 202) : new JsonResponse($response);
    }

    private function protectedResourceMetadataResponse(ServerRequestInterface $request): ResponseInterface
    {
        $authkitDomain = $this->configuration->getMcpAuthkitDomain();
        return new JsonResponse([
            'resource' => PathUtility::buildAbsoluteUrlFromRequest($request, $this->configuration->getMcpServerPath()),
            'authorization_servers' => $authkitDomain !== null ? [$authkitDomain] : [],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    private function authorizationServerMetadataResponse(): ResponseInterface
    {
        $authkitDomain = $this->configuration->getMcpAuthkitDomain();
        if ($authkitDomain === null) {
            return new JsonResponse(['error' => 'No AuthKit domain is configured for MCP.'], 503);
        }

        try {
            $response = $this->requestFactory->request(
                rtrim($authkitDomain, '/') . '/.well-known/oauth-authorization-server',
                'GET',
                ['timeout' => 5],
                'workos-auth-mcp'
            );
            $decoded = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->logger?->warning('TYPO3 MCP authorization metadata proxy failed: ' . SecretRedactor::redact($exception->getMessage()));
            return new JsonResponse(['error' => 'AuthKit authorization server metadata is unavailable.'], 502);
        }

        return is_array($decoded) ? new JsonResponse($decoded) : new JsonResponse(['error' => 'Invalid AuthKit metadata response.'], 502);
    }

    private function unauthorizedResponse(ServerRequestInterface $request, string $message): ResponseInterface
    {
        return $this->withMcpProtocolHeader(new JsonResponse(
            ['error' => $message],
            401,
            [
                'WWW-Authenticate' => sprintf(
                    'Bearer error="unauthorized", error_description="Authorization needed", resource_metadata="%s"',
                    PathUtility::buildAbsoluteUrlFromRequest($request, $this->configuration->getMcpProtectedResourceMetadataPath())
                ),
            ]
        ));
    }

    private function getRelativePath(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return PathUtility::normalizePath($request->getUri()->getPath());
        }

        return PathUtility::getPathRelativeToSiteBase(
            $request->getUri()->getPath(),
            $site->getBase()->getPath()
        );
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeJsonObject(mixed $value): ?array
    {
        if (!is_array($value) || $this->isList($value)) {
            return null;
        }

        $object = [];
        foreach ($value as $key => $item) {
            $object[(string)$key] = $item;
        }
        return $object;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRpcError(mixed $id, int $code, string $message): array
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

    private function withMcpProtocolHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('MCP-Protocol-Version', '2025-11-25');
    }
}
