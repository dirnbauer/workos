<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Mcp\McpAuthenticationService;
use Webconsulting\WorkosAuth\Mcp\McpJsonRpcService;
use Webconsulting\WorkosAuth\Mcp\McpTokenClaimsValidator;
use Webconsulting\WorkosAuth\Mcp\WorkosMcpRegistryService;
use Webconsulting\WorkosAuth\Middleware\McpServerMiddleware;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\WorkosClientFactory;

final class McpServerMiddlewareTest extends TestCase
{
    public function testUnrelatedRequestsArePassedToTheNextHandler(): void
    {
        $response = $this->createMiddleware()->process(self::request('GET', '/some/page'), $this->passThroughHandler());

        self::assertSame('passed', (string)$response->getBody());
    }

    public function testEverythingIsPassedThroughWhenMcpIsDisabled(): void
    {
        $response = $this->createMiddleware(['mcpEnabled' => '0'])->process(self::request('POST', '/workos-auth/mcp', '{}'), $this->passThroughHandler());

        self::assertSame('passed', (string)$response->getBody());
    }

    public function testProtectedResourceMetadataPointsToAuthkitAndTheEndpoint(): void
    {
        $response = $this->createMiddleware(['mcpAuthkitDomain' => 'https://example.authkit.app'])
            ->process(self::request('GET', '/.well-known/oauth-protected-resource'), $this->failingHandler());

        self::assertSame([
            'resource' => 'https://app.local/workos-auth/mcp',
            'authorization_servers' => ['https://example.authkit.app'],
            'bearer_methods_supported' => ['header'],
        ], self::json($response));
    }

    public function testGetOnTheEndpointIsRejectedWithAllowHeader(): void
    {
        $response = $this->createMiddleware()->process(self::request('GET', '/workos-auth/mcp'), $this->failingHandler());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame(McpJsonRpcService::PROTOCOL_VERSION, $response->getHeaderLine('MCP-Protocol-Version'));
    }

    public function testMissingTokenYieldsUnauthorizedWithResourceMetadataHint(): void
    {
        $response = $this->createMiddleware(['mcpAuthenticationMode' => 'workos', 'mcpAuthkitDomain' => 'https://example.authkit.app'])
            ->process(self::request('POST', '/workos-auth/mcp', '{}'), $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('resource_metadata="https://app.local/.well-known/oauth-protected-resource"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testEmptyAndInvalidBodiesAreParseErrors(): void
    {
        $middleware = $this->createMiddleware();

        $empty = $middleware->process(self::request('POST', '/workos-auth/mcp', ''), $this->failingHandler());
        self::assertSame(400, $empty->getStatusCode());
        self::assertSame(-32700, self::json($empty)['error']['code'] ?? null);

        $invalid = $middleware->process(self::request('POST', '/workos-auth/mcp', '{not json'), $this->failingHandler());
        self::assertSame(400, $invalid->getStatusCode());

        $scalar = $middleware->process(self::request('POST', '/workos-auth/mcp', '"string"'), $this->failingHandler());
        self::assertSame(400, $scalar->getStatusCode());
        self::assertSame(-32600, self::json($scalar)['error']['code'] ?? null);
    }

    public function testSingleRequestReturnsASingleResponseObject(): void
    {
        $response = $this->createMiddleware()->process(
            self::request('POST', '/workos-auth/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}'),
            $this->failingHandler()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::json($response)['id']);
        self::assertSame(McpJsonRpcService::PROTOCOL_VERSION, $response->getHeaderLine('MCP-Protocol-Version'));
    }

    public function testBatchRequestsReturnAListAndSkipNotifications(): void
    {
        $body = '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"},"junk"]';

        $response = $this->createMiddleware()->process(self::request('POST', '/workos-auth/mcp', $body), $this->failingHandler());

        $decoded = self::json($response);
        self::assertCount(2, $decoded);
        self::assertSame(1, $decoded[0]['id']);
        self::assertSame(-32600, $decoded[1]['error']['code'] ?? null);
    }

    public function testNotificationsOnlyYieldAccepted(): void
    {
        $response = $this->createMiddleware()->process(
            self::request('POST', '/workos-auth/mcp', '{"jsonrpc":"2.0","method":"notifications/initialized"}'),
            $this->failingHandler()
        );

        self::assertSame(202, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     */
    private function createMiddleware(array $rawConfiguration = []): McpServerMiddleware
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($rawConfiguration + ['mcpAuthenticationMode' => 'anonymous', 'mcpWorkosDiscovery' => '0']);
        $configuration = new WorkosConfiguration(
            $extensionConfiguration,
            self::createStub(CacheManager::class),
            new LabelTranslator(self::createStub(LanguageServiceFactory::class)),
        );

        // Real authentication service: without an Authorization header it either
        // yields the anonymous context or throws "missing token" - no WorkOS call.
        $requestFactory = self::createStub(RequestFactory::class);
        $authentication = new McpAuthenticationService(
            $configuration,
            $requestFactory,
            new IdentityService(self::createStub(ConnectionPool::class), new Context()),
            self::createStub(ConnectionPool::class),
            new McpTokenClaimsValidator(),
        );

        return new McpServerMiddleware(
            $configuration,
            $authentication,
            new McpJsonRpcService($configuration, new WorkosMcpRegistryService($configuration, new WorkosClientFactory($configuration))),
            $requestFactory,
        );
    }

    private static function request(string $method, string $path, string $body = ''): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return new ServerRequest(new Uri('https://app.local' . $path), $method, $stream);
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        $handler = self::createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new JsonResponse(['passed'])->withBody(self::stream('passed')));

        return $handler;
    }

    private function failingHandler(): RequestHandlerInterface
    {
        $handler = self::createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new \LogicException('The middleware must answer this request itself.'));

        return $handler;
    }

    private static function stream(string $content): Stream
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        return $stream;
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
