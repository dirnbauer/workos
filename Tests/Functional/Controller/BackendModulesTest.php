<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Controller\Backend\McpConfigurationController;
use Webconsulting\WorkosAuth\Controller\Backend\SetupAssistantController;
use Webconsulting\WorkosAuth\Controller\Backend\UserManagementController;

/**
 * The three WorkOS modules render inside the Core module layout, with a
 * document header, and the setup module never prints the stored API key.
 */
final class BackendModulesTest extends FunctionalTestCase
{
    private const string STORED_API_KEY = 'sk_test_supersecret_4f2a';

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
                'apiKey' => self::STORED_API_KEY,
                'clientId' => 'client_dummy',
                'mcpAuthenticationMode' => 'workos',
                'mcpAuthkitDomain' => 'https://example.authkit.app',
            ],
        ],
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    public function testTheSetupModuleIsANativeModulePageWithAWriteOnlyApiKey(): void
    {
        $body = (string)$this->get(SetupAssistantController::class)->indexAction($this->moduleRequest('workos_setup'))->getBody();

        self::assertStringContainsString('module-docheader', $body);
        self::assertStringContainsString('<h1>WorkOS Setup Assistant</h1>', $body);
        self::assertStringContainsString('form="' . SetupAssistantController::FORM_ID . '"', $body, 'Save lives in the document header');
        self::assertStringContainsString('<typo3-copy-to-clipboard', $body);
        self::assertStringNotContainsString(self::STORED_API_KEY, $body, 'The stored API key is never rendered');
        self::assertStringContainsString('sk_…4f2a', $body);
        self::assertStringNotContainsString('name="configuration[mcpEnabled]"', $body, 'MCP settings live in their own module');
    }

    public function testSavingTheSetupKeepsTheStoredKeyAndTheMcpSettings(): void
    {
        // What the Core RequestTokenMiddleware hands over once the posted
        // token of the setup form has been verified.
        SecurityAspect::provideIn($this->get(Context::class))
            ->setReceivedRequestToken(RequestToken::create('workos/backend/setup'));

        $response = $this->get(SetupAssistantController::class)->saveAction(
            $this->moduleRequest('workos_setup.save', 'POST')->withParsedBody([
                'configuration' => [
                    'apiKey' => '',
                    'clientId' => 'client_changed',
                    'frontendEnabled' => '0',
                    'backendEnabled' => '1',
                ],
            ])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/module/workos/setup', $response->getHeaderLine('Location'));
        $configuration = $this->get(WorkosConfiguration::class);
        $settings = $configuration->all();
        self::assertSame(self::STORED_API_KEY, $settings['apiKey'], 'An empty key field keeps the stored key');
        self::assertSame('client_changed', $settings['clientId']);
        self::assertFalse($settings['frontendEnabled']);
        self::assertSame('workos', $settings['mcpAuthenticationMode'], 'The setup form must not reset MCP settings');
        self::assertSame('https://example.authkit.app', $settings['mcpAuthkitDomain']);
        self::assertNotEmpty(
            $this->get(FlashMessageService::class)->getMessageQueueByIdentifier(FlashMessageQueue::FLASHMESSAGE_QUEUE)->getAllMessages(),
            'The result is reported in the Core flash queue the module layout renders'
        );
    }

    public function testTheMcpModuleIsANativeModulePage(): void
    {
        $body = (string)$this->get(McpConfigurationController::class)->indexAction($this->moduleRequest('workos_mcp'))->getBody();

        self::assertStringContainsString('module-docheader', $body);
        self::assertStringContainsString('form="' . McpConfigurationController::FORM_ID . '"', $body);
        self::assertStringContainsString('badge badge-', $body);
        self::assertStringNotContainsString('bg-success', $body, 'Badges use the Core tokens, not Bootstrap utilities');
    }

    public function testAPasswordSessionWithoutWorkosLinkOffersTheWorkosSignIn(): void
    {
        // Core keeps the backend user in $GLOBALS['BE_USER'] only; the request
        // carries no "backend.user" attribute, exactly as in a real backend.
        $body = (string)$this->get(UserManagementController::class)->indexAction($this->moduleRequest('workos_users'))->getBody();

        self::assertStringContainsString('module-docheader', $body);
        self::assertStringContainsString('No WorkOS session for this backend user', $body);
        self::assertStringContainsString(
            'href="/typo3/workos-auth/backend/login?returnTo=%2Ftypo3%2Fmain%3Fredirect%3Dworkos_users"',
            $body,
            'The sign-in starts the backend WorkOS login and returns to this module',
        );
        self::assertStringContainsString('class="btn btn-primary"', $body);
        self::assertStringNotContainsString('No backend user session could be detected', $body);
    }

    public function testARequestWithoutBackendUserAsksToLogInAgain(): void
    {
        // A backend user object without a user record: no session behind it.
        $request = $this->moduleRequest('workos_users')->withAttribute('backend.user', new BackendUserAuthentication());

        $body = (string)$this->get(UserManagementController::class)->indexAction($request)->getBody();

        self::assertStringContainsString('No backend user session could be detected. Please log in again.', $body);
        self::assertStringNotContainsString('workos-auth/backend/login', $body);
    }

    /**
     * One module per test: the document header's button bar is a shared
     * service, so a second render in the same process would see the first
     * module's buttons.
     *
     * @return array<string, array{string, bool}>
     */
    public static function modules(): array
    {
        return [
            'setup' => ['workos_setup', true],
            'mcp' => ['workos_mcp', true],
            'users' => ['workos_users', false],
        ];
    }

    #[DataProvider('modules')]
    public function testEveryModuleSpeaksGermanIncludingTheDocumentHeader(string $route, bool $hasSaveButton): void
    {
        $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $request = $this->moduleRequest($route);

        $response = match ($route) {
            'workos_setup' => $this->get(SetupAssistantController::class)->indexAction($request),
            'workos_mcp' => $this->get(McpConfigurationController::class)->indexAction($request),
            default => $this->get(UserManagementController::class)->indexAction($request),
        };
        $body = (string)$response->getBody();

        self::assertStringContainsString('title="Neu laden"', $body, 'Reload is German');
        self::assertStringNotContainsString('title="Reload"', $body);
        if ($hasSaveButton) {
            self::assertMatchesRegularExpression('/<button[^>]*title="Speichern"[^>]*>.*?Speichern/s', $body, 'Save is German');
            self::assertStringNotContainsString('title="Save"', $body);
        } else {
            self::assertStringContainsString('Keine WorkOS-Sitzung für diesen Backend-Benutzer', $body);
            self::assertStringContainsString('Mit WorkOS anmelden', $body);
            self::assertStringContainsString('login_hint=redakteurin%40example.com', $body, 'The sign-in suggests the email of the backend user');
        }
    }

    private function moduleRequest(string $routeIdentifier, string $method = 'GET'): ServerRequestInterface
    {
        $moduleIdentifier = explode('.', $routeIdentifier)[0];
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/typo3/module/workos/' . substr($moduleIdentifier, 7),
            'SCRIPT_NAME' => '/typo3/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        return new ServerRequest('https://example.com' . $serverParams['REQUEST_URI'], $method, 'php://input', [], $serverParams)
            ->withAttribute('applicationType', 2)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams))
            ->withAttribute('route', new Route($serverParams['REQUEST_URI'], ['_identifier' => $routeIdentifier, 'packageName' => 'webconsulting/workos-auth']))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule($moduleIdentifier));
    }
}
