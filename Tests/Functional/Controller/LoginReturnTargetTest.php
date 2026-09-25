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
 * The Login plugin carries a requested return target as a same-site path
 * that never contains an earlier one, and carries none when nobody asked
 * for one. 2.3.1 embedded the whole current URL, `returnTo` included, so
 * every sign-in / sign-up toggle nested the previous URL; a few rounds ended
 * in `414 URI Too Long`. 2.3.2 carried the login page itself, so a sign-in
 * there ended on the login page instead of `frontendSuccessRedirect`.
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
                'frontendSuccessRedirect' => '/welcome',
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
        self::assertStringNotContainsString('returnTo', $signUpLinks[0], 'Without a requested target the links carry none');
        self::assertStringNotContainsString('returnTo', $signInLinks[0]);
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

        // Flattened, the target is the login page itself: no target at all.
        self::assertStringNotContainsString('returnTo', $this->pluginLink($html, $action === 'show' ? 'signUp' : 'show'));
        self::assertStringNotContainsString('returnTo%5D%3D', $html, 'No link carries a nested return target');
    }

    public function testARequestedReturnTargetSurvivesEveryToggle(): void
    {
        $url = '/login?returnTo=' . rawurlencode('/members?tab=2');
        for ($round = 0; $round < 8; $round++) {
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

    public function testAForeignReturnTargetIsDropped(): void
    {
        foreach (['https://evil.example/phish', '//evil.example/phish', '/\\evil.example'] as $foreign) {
            $html = $this->render('/login?returnTo=' . rawurlencode($foreign));

            self::assertStringNotContainsString('returnTo', $this->pluginLink($html, 'signUp'), $foreign);
            self::assertStringNotContainsString('evil.example', $html, $foreign);
        }
    }

    public function testTheLoginPageAsRequestedTargetCountsAsNone(): void
    {
        // What 2.3.2 printed into its links: the login page itself.
        foreach (['/login', '/login/', self::BASE . '/login', '/login#top'] as $loginPage) {
            $html = $this->render('/login?returnTo=' . rawurlencode($loginPage));

            self::assertStringNotContainsString('returnTo', $html, $loginPage);
        }
    }

    public function testTheSignUpFormPostsItsReturnTargetFlattened(): void
    {
        $signUpPage = $this->render($this->pluginLink($this->render('/login'), 'signUp'));
        self::assertSame(1, preg_match('/<form[^>]+action="([^"]+signUpSubmit[^"]+)"/', $signUpPage, $form), 'The sign-up form is rendered');
        $nested = static fn(string $page): string => self::BASE . $page . '?' . http_build_query(['tx_workosauth_login' => [
            'action' => 'show',
            'returnTo' => self::BASE . '/login?' . http_build_query(['returnTo' => '/deeper']),
        ]]);

        // No request token: the plugin answers with the sign-up form again,
        // which keeps the (sanitized) return target of the submission.
        foreach (['/members' => '/members', '/login' => ''] as $page => $expected) {
            $response = $this->executeFrontendSubRequest(
                new InternalRequest(self::BASE . html_entity_decode($form[1]))
                    ->withMethod('POST')
                    ->withParsedBody(['tx_workosauth_login' => ['returnTo' => $nested($page)]])
            );

            self::assertSame(303, $response->getStatusCode());
            self::assertSame('signUp', self::pluginArgument($response->getHeaderLine('Location'), 'action'));
            self::assertSame($expected, self::pluginArgument($response->getHeaderLine('Location'), 'returnTo'), $page);
        }
    }

    public function testTheHostedLoginLinksEndAtTheSuccessPageWithoutATarget(): void
    {
        $socialLink = static function (string $html): string {
            self::assertSame(1, preg_match('/href="(\/workos-auth\/frontend\/login\?[^"]*provider=GoogleOAuth)"/', $html, $link));

            return html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5);
        };

        self::assertSame('/welcome', $this->storedReturnTargetOf($socialLink($this->render('/login'))));
        self::assertSame('/welcome', $this->storedReturnTargetOf($socialLink($this->render('/login?returnTo=%2Flogin'))));
        self::assertSame('/members?tab=2', $this->storedReturnTargetOf($socialLink($this->render('/login?returnTo=' . rawurlencode('/members?tab=2')))));
    }

    public function testTheHostedLoginEndpointStoresAFlatReturnTarget(): void
    {
        $nested = '/login?' . http_build_query([
            'tx_workosauth_login' => ['action' => 'signUp', 'returnTo' => self::BASE . '/login?returnTo=%2Fdeeper'],
            'cHash' => 'stale',
        ]);

        self::assertSame('/login', $this->storedFrontendReturnTarget($nested));
        self::assertSame('/members?tab=2', $this->storedFrontendReturnTarget(self::BASE . '/members?tab=2&returnTo=%2Fdeeper'));
        self::assertSame('/welcome', $this->storedFrontendReturnTarget('/' . str_repeat('a', PathUtility::MAX_RETURN_TO_LENGTH)));
    }

    private function storedFrontendReturnTarget(string $returnTo): string
    {
        return $this->storedReturnTargetOf('/workos-auth/frontend/login?' . http_build_query(['returnTo' => $returnTo]));
    }

    /**
     * The return target the frontend login endpoint stores for the hosted
     * login a link starts.
     */
    private function storedReturnTargetOf(string $loginLink): string
    {
        $site = new Site('website', 1, ['base' => self::BASE . '/']);
        parse_str(MixedCaster::string(parse_url($loginLink, PHP_URL_QUERY)), $queryParams);
        $request = new ServerRequest(self::BASE . $loginLink)
            ->withQueryParams($queryParams)
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
