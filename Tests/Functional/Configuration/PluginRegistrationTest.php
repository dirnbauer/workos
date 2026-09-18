<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Configuration;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Controller\Frontend\AccountController;
use Webconsulting\WorkosAuth\Controller\Frontend\LoginController;
use Webconsulting\WorkosAuth\Controller\Frontend\TeamController;
use Webconsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider;

/**
 * ext_localconf.php contract: every plugin action is non-cacheable (they
 * depend on the frontend session and CSRF tokens) and the backend login
 * provider is registered under its public identifier.
 */
final class PluginRegistrationTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    public function testEveryPluginActionIsRegisteredAsNonCacheable(): void
    {
        $plugins = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions']['WorkosAuth']['plugins'] ?? null;
        self::assertIsArray($plugins);
        self::assertSame(['Login', 'Account', 'Team'], array_keys($plugins));

        $expectedControllers = [
            'Login' => LoginController::class,
            'Account' => AccountController::class,
            'Team' => TeamController::class,
        ];
        foreach ($expectedControllers as $pluginName => $controllerClass) {
            $controller = $plugins[$pluginName]['controllers'][$controllerClass] ?? null;
            self::assertIsArray($controller, $pluginName);
            self::assertNotEmpty($controller['actions']);
            self::assertSame($controller['actions'], $controller['nonCacheableActions'], $pluginName . ' actions must all be non-cacheable');
        }
    }

    public function testBackendLoginProviderIsRegistered(): void
    {
        $provider = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][WorkosBackendLoginProvider::IDENTIFIER] ?? null;

        self::assertIsArray($provider);
        self::assertSame(WorkosBackendLoginProvider::class, $provider['provider']);
    }

    public function testStateCacheIsConfigured(): void
    {
        $cache = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['workos_auth_state'] ?? null;

        self::assertIsArray($cache);
        self::assertSame(['system'], $cache['groups']);
    }
}
