<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Tests\Functional\Fixtures\FakeWorkosApi;

/**
 * Where a sign-in through the Login plugin ends: the target the visitor
 * asked for, else the configured success page (`frontendSuccessRedirect`).
 *
 * 2.3.2 made the login page itself the default target of the plugin's forms
 * and links, so a visitor who signed in there stayed on the login page. The
 * sign-ins run through the TYPO3 frontend against a faked WorkOS API.
 */
final class SignInLandingTest extends FunctionalTestCase
{
    private const string BASE = 'https://website.local';
    private const string SUCCESS_PAGE = '/welcome';
    private const string MEMBERS = '/members?tab=2';

    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
        __DIR__ . '/../Fixtures/Extensions/workos_fake_api',
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
                'frontendLinkByEmail' => '1',
                'frontendSuccessRedirect' => self::SUCCESS_PAGE,
            ],
        ],
        // How an integrator links into the login page with `?returnTo=`
        // (see Usage): without it, TYPO3 answers such a link with 404.
        'FE' => [
            'cacheHash' => [
                'excludedParameters' => ['returnTo'],
            ],
        ],
    ];

    /**
     * Cookies the frontend set so far (session, request-token nonce).
     *
     * @var array<string, string>
     */
    private array $cookies = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        FakeWorkosApi::reset();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Login', 'slug' => '/login', 'doktype' => 1]);
        $this->getConnectionPool()->getConnectionForTable('fe_users')->insert('fe_users', [
            'pid' => 1,
            'username' => 'visitor',
            'password' => 'unused',
            'email' => FakeWorkosApi::EMAIL,
        ]);
        $this->get(SiteWriter::class)->createNewBasicSite('website', 1, self::BASE . '/');
        $this->setUpFrontendRootPage(1, ['EXT:workos_auth/Tests/Functional/Fixtures/LoginPlugin.typoscript']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function landingProvider(): array
    {
        return [
            'no target: the success page' => ['/login', self::SUCCESS_PAGE],
            'a requested target' => ['/login?returnTo=' . rawurlencode(self::MEMBERS), self::MEMBERS],
            'the login page as target: the success page' => ['/login?returnTo=%2Flogin', self::SUCCESS_PAGE],
            'a foreign target: the success page' => ['/login?returnTo=' . rawurlencode('https://evil.example/'), self::SUCCESS_PAGE],
        ];
    }

    #[DataProvider('landingProvider')]
    public function testThePasswordSignInLands(string $loginPage, string $expectedLanding): void
    {
        [$action, $fields] = $this->form($this->page($loginPage), 'passwordAuth');

        $response = $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL, 'password' => 'faked-api-password']);

        $this->assertSignedInAt($expectedLanding, $response);
    }

    #[DataProvider('landingProvider')]
    public function testTheEmailCodeSignInLands(string $loginPage, string $expectedLanding): void
    {
        [$action, $fields] = $this->form($this->page($loginPage), 'magicAuthSend');
        $codeStep = $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL]);
        self::assertSame('magicAuthCode', self::pluginArgument($codeStep->getHeaderLine('Location'), 'action'));

        [$action, $fields] = $this->form($this->page($codeStep->getHeaderLine('Location')), 'magicAuthVerify');
        $response = $this->post($action, $fields + ['code' => '123456']);

        $this->assertSignedInAt($expectedLanding, $response);
    }

    #[DataProvider('landingProvider')]
    public function testTheEmailVerificationStepLands(string $loginPage, string $expectedLanding): void
    {
        FakeWorkosApi::$requireEmailVerification = true;
        [$action, $fields] = $this->form($this->page($loginPage), 'passwordAuth');
        $verificationStep = $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL, 'password' => 'faked-api-password']);
        self::assertSame('verifyEmail', self::pluginArgument($verificationStep->getHeaderLine('Location'), 'action'));

        [$action, $fields] = $this->form($this->page($verificationStep->getHeaderLine('Location')), 'verifyEmailSubmit');
        $response = $this->post($action, $fields + ['code' => '654321']);

        $this->assertSignedInAt($expectedLanding, $response);
    }

    #[DataProvider('landingProvider')]
    public function testTheSignUpLands(string $loginPage, string $expectedLanding): void
    {
        $signUpPage = $this->page($this->pluginLink($this->page($loginPage), 'signUp'));
        [$action, $fields] = $this->form($signUpPage, 'signUpSubmit');

        $response = $this->post($action, $fields + [
            'firstName' => 'Vera',
            'lastName' => 'Visitor',
            'email' => FakeWorkosApi::EMAIL,
            'password' => 'faked-api-password',
            'passwordConfirm' => 'faked-api-password',
        ]);

        self::assertContains('POST /user_management/users', FakeWorkosApi::$calls, 'The account was created');
        $this->assertSignedInAt($expectedLanding, $response);
    }

    public function testWithoutATargetThePluginPrintsNone(): void
    {
        $signIn = $this->page('/login');
        $signUp = $this->page($this->pluginLink($signIn, 'signUp'));
        $signInAgain = $this->page($this->pluginLink($signUp, 'show'));

        foreach (['sign-in' => $signIn, 'sign-up' => $signUp, 'sign-in after a toggle' => $signInAgain] as $view => $html) {
            self::assertStringNotContainsString('returnTo', $html, $view . ': no form, toggle or hosted-login link carries a target');
        }
        self::assertStringContainsString('href="/workos-auth/frontend/login?provider=GoogleOAuth"', $signIn);
    }

    public function testFormsPrintedBy232WithTheLoginPageLandOnTheSuccessPage(): void
    {
        // 2.3.2 posted the login page as target; with or without the
        // trailing slash TYPO3 serves the same page.
        foreach (['/login', '/login/', self::BASE . '/login'] as $printedTarget) {
            $this->cookies = [];
            [$action, $fields] = $this->form($this->page('/login'), 'passwordAuth');
            $fields['tx_workosauth_login'] = ['returnTo' => $printedTarget] + MixedCaster::stringKeyedArray($fields['tx_workosauth_login'] ?? null);

            $response = $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL, 'password' => 'faked-api-password']);

            self::assertSame(self::SUCCESS_PAGE, $response->getHeaderLine('Location'), $printedTarget);
        }
    }

    public function testSigningOutStillReturnsToTheCurrentPage(): void
    {
        [$action, $fields] = $this->form($this->page('/login'), 'passwordAuth');
        $this->assertSignedInAt(self::SUCCESS_PAGE, $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL, 'password' => 'faked-api-password']));

        self::assertStringContainsString('href="/workos-auth/frontend/logout?returnTo=%2Flogin"', $this->page('/login'));
        self::assertStringContainsString(
            'href="/workos-auth/frontend/logout?returnTo=%2Fmembers%3Ftab%3D2"',
            $this->page('/login?returnTo=' . rawurlencode(self::MEMBERS)),
            'A requested target applies to signing out, as before'
        );
    }

    public function testAFailedSignUpKeepsNoTargetOrTheRequestedOne(): void
    {
        foreach (['/login' => '', '/login?returnTo=' . rawurlencode(self::MEMBERS) => self::MEMBERS] as $loginPage => $expected) {
            $this->cookies = [];
            [$action, $fields] = $this->form($this->page($this->pluginLink($this->page($loginPage), 'signUp')), 'signUpSubmit');

            $response = $this->post($action, $fields + ['email' => FakeWorkosApi::EMAIL, 'password' => 'short', 'passwordConfirm' => 'short']);

            self::assertSame(303, $response->getStatusCode());
            self::assertSame('signUp', self::pluginArgument($response->getHeaderLine('Location'), 'action'));
            self::assertSame($expected, self::pluginArgument($response->getHeaderLine('Location'), 'returnTo'), $loginPage);
            self::assertNotContains('POST /user_management/users', FakeWorkosApi::$calls);
        }
    }

    private function assertSignedInAt(string $expectedLanding, ResponseInterface $response): void
    {
        self::assertSame(303, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($expectedLanding, $response->getHeaderLine('Location'));
        self::assertArrayHasKey('fe_typo_user', $this->cookies, 'A frontend session was opened');
    }

    /**
     * GET a page with the cookies collected so far; returns its HTML.
     */
    private function page(string $url): string
    {
        $response = $this->send(new InternalRequest(self::absolute($url)));
        self::assertSame(200, $response->getStatusCode(), $url);

        return (string)$response->getBody();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $url, array $fields): ResponseInterface
    {
        // The encoded body is given too: the testing framework cannot encode
        // the nested fields of an Extbase form (`tx_…[__referrer][@action]`).
        $body = new Stream('php://temp', 'rw');
        $body->write(http_build_query($fields));

        return $this->send(
            new InternalRequest(self::absolute($url))
                ->withMethod('POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($body)
                ->withParsedBody($fields)
        );
    }

    private function send(InternalRequest $request): ResponseInterface
    {
        $response = $this->executeFrontendSubRequest($request->withCookieParams($this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = Cookie::fromString($header);
            if ($cookie->isCleared() || (string)$cookie->getValue() === '') {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string)$cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * Action URL and hidden fields of the plugin form that posts to $action,
     * the way a browser submits them (visible fields are up to the caller).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function form(string $html, string $action): array
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]*)"[^>]*>(.*?)<\/form>/s', $html, $forms, PREG_SET_ORDER);
        foreach ($forms as [, $url, $inner]) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
            if (self::pluginArgument($url, 'action') !== $action) {
                continue;
            }
            preg_match_all('/<input\b[^>]*\btype="hidden"[^>]*>/', $inner, $inputs);
            $pairs = [];
            foreach ($inputs[0] as $input) {
                preg_match('/\bname="([^"]*)"/', $input, $name);
                preg_match('/\bvalue="([^"]*)"/', $input, $value);
                $pairs[] = rawurlencode(html_entity_decode($name[1] ?? '', ENT_QUOTES | ENT_HTML5))
                    . '=' . rawurlencode(html_entity_decode($value[1] ?? '', ENT_QUOTES | ENT_HTML5));
            }
            parse_str(implode('&', $pairs), $fields);

            return [$url, MixedCaster::stringKeyedArray($fields) ?? []];
        }

        self::fail(sprintf('No form posting to the "%s" action rendered.', $action));
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

    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? self::BASE . $url : $url;
    }
}
