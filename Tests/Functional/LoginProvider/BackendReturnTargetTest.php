<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\LoginProvider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider;
use Webconsulting\WorkosAuth\Middleware\BackendWorkosAuthMiddleware;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\StateService;

/**
 * The backend "Login with WorkOS" links continue with the route TYPO3 asked
 * the login screen to open, as `/typo3/main?redirect=<route>`, and the
 * login endpoint stores a flat return target however deeply the requested
 * one nests earlier ones.
 */
final class BackendReturnTargetTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'workos_auth' => [
                'apiKey' => 'sk_test_dummy',
                'clientId' => 'client_dummy',
            ],
        ],
    ];

    public function testTheLoginLinkContinuesWithTheRequestedBackendRoute(): void
    {
        $loginUrl = $this->loginProviderUrl(['redirect' => 'workos_users']);

        self::assertSame('/typo3/workos-auth/backend/login?returnTo=%2Ftypo3%2Fmain%3Fredirect%3Dworkos_users', $loginUrl);
    }

    public function testRouteParametersKeepOnlyWhatTheRouteNeeds(): void
    {
        $loginUrl = $this->loginProviderUrl([
            'redirect' => 'web_layout',
            'redirectParams' => 'id=12&token=stale&redirect=web_list&redirectParams=' . rawurlencode('id=13'),
        ]);
        parse_str(MixedCaster::string(parse_url($loginUrl, PHP_URL_QUERY)), $query);

        self::assertSame('/typo3/main?redirect=web_layout&redirectParams=id%3D12', $query['returnTo'] ?? null);
    }

    public function testWithoutARequestedRouteTheLoginLinkCarriesNoReturnTarget(): void
    {
        self::assertSame('/typo3/workos-auth/backend/login', $this->loginProviderUrl([]));
        self::assertSame('/typo3/workos-auth/backend/login', $this->loginProviderUrl(['redirect' => ['nested' => 'array']]));
    }

    public function testTheLoginEndpointStoresAFlatReturnTarget(): void
    {
        $nested = '/typo3/main?redirect=workos_users';
        for ($level = 0; $level < 5; $level++) {
            $nested = 'https://example.com/typo3/main?' . http_build_query(['redirect' => 'workos_users', 'returnTo' => $nested]);
        }

        self::assertSame('/typo3/main?redirect=workos_users', $this->storedReturnTarget($nested));
        self::assertSame('/typo3/main?redirect=workos_users', $this->storedReturnTarget('/typo3/main?redirect=workos_users&workosMessage=abc'));
        self::assertSame('/typo3/main', $this->storedReturnTarget('https://evil.example/typo3/main'));
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function loginProviderUrl(array $queryParams): string
    {
        $request = $this->backendRequest('https://example.com/typo3/login', 'GET')
            ->withQueryParams(['loginProvider' => WorkosBackendLoginProvider::IDENTIFIER] + $queryParams);
        $view = new class implements ViewInterface {
            /** @var array<string, mixed> */
            public array $variables = [];

            #[\Override]
            public function assign(string $key, mixed $value): self
            {
                $this->variables[$key] = $value;
                return $this;
            }

            #[\Override]
            public function assignMultiple(array $values): self
            {
                $this->variables = [...$this->variables, ...$values];
                return $this;
            }

            #[\Override]
            public function render(string $templateFileName = ''): string
            {
                return '';
            }
        };

        $this->get(WorkosBackendLoginProvider::class)->modifyView($request, $view);

        return MixedCaster::string($view->variables['loginUrl'] ?? null);
    }

    private function storedReturnTarget(string $returnTo): string
    {
        $request = $this->backendRequest('https://example.com/typo3/workos-auth/backend/login', 'GET')
            ->withQueryParams(['returnTo' => $returnTo]);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = $this->get(BackendWorkosAuthMiddleware::class)->process($request, $handler);

        self::assertSame(302, $response->getStatusCode());
        parse_str(MixedCaster::string(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY)), $query);
        $state = json_decode(MixedCaster::string($query['state'] ?? null), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $cookie = Cookie::fromString($response->getHeaderLine('Set-Cookie'));
        $payload = $this->get(StateService::class)->peek(
            new ServerRequest('https://example.com/typo3/workos-auth/backend/callback')->withCookieParams([$cookie->getName() => $cookie->getValue()]),
            'backend',
            MixedCaster::string($state['token'] ?? null)
        );

        return MixedCaster::string($payload['returnTo'] ?? null);
    }

    private function backendRequest(string $uri, string $method): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => (string)parse_url($uri, PHP_URL_PATH),
            'SCRIPT_NAME' => '/typo3/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        return new ServerRequest($uri, $method, 'php://input', [], $serverParams)
            ->withAttribute('applicationType', 2)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
    }
}
