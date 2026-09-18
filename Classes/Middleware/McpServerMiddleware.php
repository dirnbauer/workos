<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Mcp\McpAuthenticationException;
use Webconsulting\WorkosAuth\Mcp\McpAuthenticationService;
use Webconsulting\WorkosAuth\Mcp\McpJsonRpcService;
use Webconsulting\WorkosAuth\Mcp\McpRequestContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\PathUtility;

/**
 * Streamable HTTP MCP endpoint plus the two OAuth discovery documents MCP
 * clients use to find the AuthKit authorization server.
 */
#[Autoconfigure(public: true)]
final class McpServerMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly McpAuthenticationService $authenticationService,
        private readonly McpJsonRpcService $jsonRpcService,
        private readonly RequestFactory $requestFactory,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->configuration->isMcpEnabled()) {
            return $handler->handle($request);
        }

        return match ($this->getRelativePath($request)) {
            WorkosConfiguration::MCP_PROTECTED_RESOURCE_METADATA_PATH => $this->protectedResourceMetadataResponse($request),
            WorkosConfiguration::MCP_AUTHORIZATION_SERVER_METADATA_PATH => $this->authorizationServerMetadataResponse(),
            $this->configuration->getMcpServerPath() => $this->handleMcpRequest($request),
            default => $handler->handle($request),
        };
    }

    private function handleMcpRequest(ServerRequestInterface $request): ResponseInterface
    {
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
            return $this->withMcpProtocolHeader(new JsonResponse(McpJsonRpcService::error(null, -32603, 'Internal MCP server error.'), 500));
        }
    }

    private function handleJsonRpc(ServerRequestInterface $request, McpRequestContext $context): ResponseInterface
    {
        $rawBody = trim((string)$request->getBody());
        if ($rawBody === '') {
            return new JsonResponse(McpJsonRpcService::error(null, -32700, 'Empty JSON-RPC request body.'), 400);
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(McpJsonRpcService::error(null, -32700, 'Invalid JSON request body.'), 400);
        }

        // A JSON array is a batch; a JSON object a single message.
        $isBatch = is_array($decoded) && array_is_list($decoded);
        $responses = [];
        foreach ($isBatch ? $decoded : [$decoded] as $message) {
            $jsonRpcMessage = self::jsonObject($message);
            if ($jsonRpcMessage === null) {
                $error = McpJsonRpcService::error(null, -32600, 'Invalid JSON-RPC request.');
                if (!$isBatch) {
                    return new JsonResponse($error, 400);
                }
                $responses[] = $error;
                continue;
            }
            $response = $this->jsonRpcService->handle($jsonRpcMessage, $context);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            return new Response(null, 202);
        }

        return new JsonResponse($isBatch ? $responses : $responses[0]);
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

    /**
     * Proxies AuthKit's own authorization-server metadata for clients that
     * expect that compatibility path on the MCP server itself.
     */
    private function authorizationServerMetadataResponse(): ResponseInterface
    {
        $authkitDomain = $this->configuration->getMcpAuthkitDomain();
        if ($authkitDomain === null) {
            return new JsonResponse(['error' => 'No AuthKit domain is configured for MCP.'], 503);
        }

        try {
            $response = $this->requestFactory->request(
                $authkitDomain . WorkosConfiguration::MCP_AUTHORIZATION_SERVER_METADATA_PATH,
                'GET',
                ['timeout' => 5],
                'workos-auth-mcp'
            );
            $decoded = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->logger?->warning('TYPO3 MCP authorization metadata proxy failed: ' . SecretRedactor::redact($exception->getMessage()));
            return new JsonResponse(['error' => 'AuthKit authorization server metadata is unavailable.'], 502);
        }

        return is_array($decoded)
            ? new JsonResponse($decoded)
            : new JsonResponse(['error' => 'Invalid AuthKit metadata response.'], 502);
    }

    private function unauthorizedResponse(ServerRequestInterface $request, string $message): ResponseInterface
    {
        return $this->withMcpProtocolHeader(new JsonResponse(['error' => $message], 401, [
            'WWW-Authenticate' => sprintf(
                'Bearer error="unauthorized", error_description="Authorization needed", resource_metadata="%s"',
                PathUtility::buildAbsoluteUrlFromRequest($request, WorkosConfiguration::MCP_PROTECTED_RESOURCE_METADATA_PATH)
            ),
        ]));
    }

    private function getRelativePath(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');

        return $site instanceof Site
            ? PathUtility::getPathRelativeToSiteBase($request->getUri()->getPath(), $site->getBase()->getPath())
            : PathUtility::normalizePath($request->getUri()->getPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jsonObject(mixed $value): ?array
    {
        return is_array($value) && !array_is_list($value) ? MixedCaster::stringKeyedArray($value) : null;
    }

    private function withMcpProtocolHeader(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('MCP-Protocol-Version', McpJsonRpcService::PROTOCOL_VERSION);
    }
}
