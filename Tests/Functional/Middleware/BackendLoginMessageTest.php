<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Middleware;

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

/**
 * Messages for the backend login screen travel as a one-shot, server-side
 * token bound to the browser — never as text in the URL. A crafted login
 * link can therefore not put words of its own onto the TYPO3 login page.
 */
final class BackendLoginMessageTest extends FunctionalTestCase
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

    public function testAFailedStepShowsItsMessageOnceOnTheLoginScreen(): void
    {
        $response = $this->postToLoginEndpoint(BackendWorkosAuthMiddleware::PASSWORD_AUTH_PATH);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/typo3/login?', $location);
        self::assertStringNotContainsString('email', urldecode($location), 'No message text in the URL');
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $token = $query[BackendWorkosAuthMiddleware::LOGIN_MESSAGE_PARAMETER] ?? '';
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $cookie = Cookie::fromString($response->getHeaderLine('Set-Cookie'));
        $binding = $cookie->getValue();
        self::assertIsString($binding);
        $first = $this->renderLoginProvider($token, [$cookie->getName() => $binding]);
        self::assertSame('Please enter both email and password.', $first['authError']);

        $second = $this->renderLoginProvider($token, [$cookie->getName() => $binding]);
        self::assertSame('', $second['authError'], 'A message is shown once');
    }

    public function testAForgedMessageTokenShowsNothing(): void
    {
        $variables = $this->renderLoginProvider('Your account is locked, call +43 1 234567', []);

        self::assertSame('', $variables['authError']);
        self::assertSame('', $variables['authNotice']);
    }

    public function testAMessageIssuedToAnotherBrowserShowsNothing(): void
    {
        $response = $this->postToLoginEndpoint(BackendWorkosAuthMiddleware::MAGIC_AUTH_SEND_PATH);
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $token = $query[BackendWorkosAuthMiddleware::LOGIN_MESSAGE_PARAMETER] ?? '';
        self::assertIsString($token);

        $variables = $this->renderLoginProvider($token, ['workos_auth_state_backend_login_message' => 'someone-else']);

        self::assertSame('', $variables['authError']);
    }

    private function postToLoginEndpoint(string $endpoint): ResponseInterface
    {
        $request = $this->backendRequest('https://example.com/typo3' . $endpoint, 'POST')->withParsedBody([]);
        $handler = new class () implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        return $this->get(BackendWorkosAuthMiddleware::class)->process($request, $handler);
    }

    /**
     * @param array<string, string> $cookies
     * @return array<string, mixed> the variables the provider assigned
     */
    private function renderLoginProvider(string $messageToken, array $cookies): array
    {
        $request = $this->backendRequest('https://example.com/typo3/login', 'GET')
            ->withQueryParams([
                'loginProvider' => WorkosBackendLoginProvider::IDENTIFIER,
                BackendWorkosAuthMiddleware::LOGIN_MESSAGE_PARAMETER => $messageToken,
            ])
            ->withCookieParams($cookies);
        $view = new class () implements ViewInterface {
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

        return $view->variables;
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
