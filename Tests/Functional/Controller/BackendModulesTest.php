<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
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

    public function testTheUserModuleExplainsWhatIsMissing(): void
    {
        $body = (string)$this->get(UserManagementController::class)->indexAction($this->moduleRequest('workos_users'))->getBody();

        self::assertStringContainsString('module-docheader', $body);
        self::assertStringContainsString('callout', $body);
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
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule($moduleIdentifier))
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
    }
}
