<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Middleware\FrontendWorkosAuthMiddleware;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\StateService;
use Webconsulting\WorkosAuth\Service\PathUtility;

/**
 * The Login plugin carries its return target as a same-site path that
 * never contains an earlier one. 2.3.1 embedded the whole current URL,
 * `returnTo` included, so every sign-in / sign-up toggle nested the
 * previous URL; a few rounds ended in `414 URI Too Long`.
 */
final class LoginReturnTargetTest extends FunctionalTestCase
{
    private const string BASE = 'https://website.local';

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
                'frontendAutoCreateUsers' => '0',
            ],
        ],
        // Lets a test replay a hand-built 2.3.1 link, which has no valid cHash.
        'FE' => [
            'cacheHash' => [
                'enforceValidation' => false,
            ],
        ],
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Login', 'slug' => '/login', 'doktype' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('website', 1, self::BASE . '/');
        $this->setUpFrontendRootPage(1, ['EXT:workos_auth/Tests/Functional/Fixtures/LoginPlugin.typoscript']);
    }

    public function testTogglingBetweenSignInAndSignUpAlwaysYieldsTheSameLinks(): void
    {
        $signUpLinks = [];
        $signInLinks = [];
        $url = '/login';
        for ($round = 0; $round < 8; $round++) {
            $signUpLinks[] = $signUp = $this->pluginLink($this->render($url), 'signUp');
            $signInLinks[] = $url = $this->pluginLink($this->render($signUp), 'show');
        }

        self::assertCount(1, array_unique($signUpLinks), 'The sign-up link does not change from toggle to toggle');
        self::assertCount(1, array_unique($signInLinks), 'The sign-in link does not change from toggle to toggle');
        self::assertSame('/login', self::pluginArgument($signUpLinks[0], 'returnTo'), 'The return target is the page path, not its URL');
        self::assertSame('/login', self::pluginArgument($signInLinks[0], 'returnTo'));
        self::assertLessThan(300, strlen($signUpLinks[0]));
    }

    public function testALinkNestedByEarlierVersionsIsFlattened(): void
    {
        // What 2.3.1 printed after a few toggles: each link nests the last,
        // until the URL is longer than any target the extension accepts.
        $nested = '/login';
        $action = 'show';
        while (strlen($nested) <= PathUtility::MAX_RETURN_TO_LENGTH) {
            $action = $action === 'show' ? 'signUp' : 'show';
            $nested = '/login?' . http_build_query(['tx_workosauth_login' => [
                'action' => $action,
                'controller' => 'Frontend\\Login',
                'returnTo' => self::BASE . $nested,
            ]]);
        }

        $html = $this->render($nested);

        self::assertSame('/login', self::pluginArgument($this->pluginLink($html, $action === 'show' ? 'signUp' : 'show'), 'returnTo'));
        self::assertStringNotContainsString('returnTo%5D%3D', $html, 'No link carries a nested return target');
    }

    public function testARequestedReturnTargetSurvivesEveryToggle(): void
    {
        $url = '/login?returnTo=' . rawurlencode('/members?tab=2');
        for ($round = 0; $round < 4; $round++) {
            $url = $this->pluginLink($this->render($this->pluginLink($this->render($url), 'signUp')), 'show');
        }

        $html = $this->render($url);

        self::assertSame('/members?tab=2', self::pluginArgument($this->pluginLink($html, 'signUp'), 'returnTo'));
        self::assertStringContainsString(
            'name="tx_workosauth_login[returnTo]" value="/members?tab=2"',
            $html,
            'The sign-in forms post the requested target'
        );
        self::assertStringContainsString(
            'href="/workos-auth/frontend/login?returnTo=%2Fmembers%3Ftab%3D2&amp;provider=GoogleOAuth"',
            $html,
            'The hosted-login links carry the requested target'
        );
    }

    public function testAForeignReturnTargetIsReplacedByThePage(): void
    {
        foreach (['https://evil.example/phish', '//evil.example/phish', '/\\evil.example'] as $foreign) {
            $html = $this->render('/login?returnTo=' . rawurlencode($foreign));

            self::assertSame('/login', self::pluginArgument($this->pluginLink($html, 'signUp'), 'returnTo'), $foreign);
            self::assertStringNotContainsString('evil.example', $html, $foreign);
        }
    }

    public function testTheSignUpFormPostsItsReturnTargetFlattened(): void
    {
        $signUpPage = $this->render($this->pluginLink($this->render('/login'), 'signUp'));
        self::assertSame(1, preg_match('/<form[^>]+action="([^"]+signUpSubmit[^"]+)"/', $signUpPage, $form), 'The sign-up form is rendered');
        $nested = self::BASE . '/login?' . http_build_query(['tx_workosauth_login' => [
            'action' => 'show',
            'returnTo' => self::BASE . '/login?' . http_build_query(['returnTo' => '/deeper']),
        ]]);

        // No request token: the plugin answers with the sign-up form again,
        // which keeps the (sanitized) return target of the submission.
        $response = $this->executeFrontendSubRequest(
            new InternalRequest(self::BASE . html_entity_decode($form[1]))
                ->withMethod('POST')
                ->withParsedBody(['tx_workosauth_login' => ['returnTo' => $nested]])
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('signUp', self::pluginArgument($response->getHeaderLine('Location'), 'action'));
        self::assertSame('/login', self::pluginArgument($response->getHeaderLine('Location'), 'returnTo'));
    }

    public function testTheHostedLoginEndpointStoresAFlatReturnTarget(): void
    {
        $nested = '/login?' . http_build_query([
            'tx_workosauth_login' => ['action' => 'signUp', 'returnTo' => self::BASE . '/login?returnTo=%2Fdeeper'],
            'cHash' => 'stale',
        ]);

        self::assertSame('/login', $this->storedFrontendReturnTarget($nested));
        self::assertSame('/members?tab=2', $this->storedFrontendReturnTarget(self::BASE . '/members?tab=2&returnTo=%2Fdeeper'));
        self::assertSame('/', $this->storedFrontendReturnTarget('/' . str_repeat('a', PathUtility::MAX_RETURN_TO_LENGTH)));
    }

    private function storedFrontendReturnTarget(string $returnTo): string
    {
        $site = new Site('website', 1, ['base' => self::BASE . '/']);
        $request = new ServerRequest(self::BASE . '/workos-auth/frontend/login?' . http_build_query(['returnTo' => $returnTo]))
            ->withQueryParams(['returnTo' => $returnTo])
            ->withAttribute('site', $site);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = $this->get(FrontendWorkosAuthMiddleware::class)->process($request, $handler);

        self::assertSame(302, $response->getStatusCode());
        parse_str(MixedCaster::string(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY)), $query);
        $state = json_decode(MixedCaster::string($query['state'] ?? null), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $cookie = Cookie::fromString($response->getHeaderLine('Set-Cookie'));
        $payload = $this->get(StateService::class)->peek(
            new ServerRequest(self::BASE . '/workos-auth/frontend/callback')->withCookieParams([$cookie->getName() => $cookie->getValue()]),
            'frontend',
            MixedCaster::string($state['token'] ?? null)
        );

        return MixedCaster::string($payload['returnTo'] ?? null);
    }

    private function render(string $pathAndQuery): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest(self::BASE . $pathAndQuery));
        self::assertSame(200, $response->getStatusCode(), $pathAndQuery);

        return (string)$response->getBody();
    }

    /**
     * The link of the plugin that switches to the given action.
     */
    private function pluginLink(string $html, string $action): string
    {
        preg_match_all('/href="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
            if (self::pluginArgument($href, 'action') === $action) {
                return $href;
            }
        }

        self::fail(sprintf('No link to the "%s" action rendered.', $action));
    }

    private static function pluginArgument(string $url, string $name): string
    {
        parse_str(MixedCaster::string(parse_url($url, PHP_URL_QUERY)), $query);
        $arguments = MixedCaster::stringKeyedArray($query['tx_workosauth_login'] ?? null) ?? [];

        return MixedCaster::string($arguments[$name] ?? null);
    }
}
