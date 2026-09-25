<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Service\PathUtility;

final class PathUtilityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizePathProvider(): array
    {
        return [
            'empty becomes root' => ['', '/'],
            'root stays root' => ['/', '/'],
            'strips trailing slash' => ['/a/b/', '/a/b'],
            'prepends leading slash' => ['a/b', '/a/b'],
            'trims whitespace' => ['  /a  ', '/a'],
        ];
    }

    #[DataProvider('normalizePathProvider')]
    public function testNormalizePath(string $input, string $expected): void
    {
        self::assertSame($expected, PathUtility::normalizePath($input));
    }

    public function testJoinBaseAndPath(): void
    {
        self::assertSame('/de/login', PathUtility::joinBaseAndPath('/de', '/login'));
        self::assertSame('/login', PathUtility::joinBaseAndPath('/', '/login'));
        self::assertSame('/de/login', PathUtility::joinBaseAndPath('/de/', 'login'));
    }

    public function testAppendQueryParametersSkipsEmptyValues(): void
    {
        $url = PathUtility::appendQueryParameters('/login', [
            'returnTo' => '/dashboard',
            'screen' => '',
            'provider' => null,
        ]);

        self::assertSame('/login?returnTo=%2Fdashboard', $url);
        self::assertSame('/login', PathUtility::appendQueryParameters('/login', ['screen' => '']));
    }

    public function testAppendQueryParametersPreservesExistingQuery(): void
    {
        self::assertSame(
            '/login?returnTo=%2Fa&screen=sign-up',
            PathUtility::appendQueryParameters('/login?returnTo=%2Fa', ['screen' => 'sign-up'])
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openRedirectProvider(): array
    {
        return [
            'protocol-relative slash slash' => ['//evil.example/path'],
            'backslash protocol' => ['\\\\evil.example/path'],
            'mixed forward-back' => ['/\\evil.example/path'],
            'mixed back-forward' => ['\\/evil.example/path'],
            // Browsers strip tabs and line breaks from a Location header
            // before resolving it: each of these becomes //evil.example.
            'tab between slashes' => ["/\t/evil.example/path"],
            'newline between slashes' => ["/\n/evil.example/path"],
            'carriage return between slashes' => ["/\r/evil.example/path"],
            'backslash after the path start' => ['/path\\..\\\\evil.example'],
            'absolute URL with a backslash host trick' => ['https://app.local\\@evil.example/'],
            'raw space' => ['/ /evil.example'],
        ];
    }

    #[DataProvider('openRedirectProvider')]
    public function testSanitizeReturnToRejectsProtocolRelativeCandidates(string $candidate): void
    {
        self::assertSame(
            '/fallback',
            PathUtility::sanitizeReturnTo(self::request('https://app.local/login'), $candidate, '/fallback'),
            sprintf('Candidate %s must not be treated as a safe path.', $candidate)
        );
    }

    public function testSanitizeReturnToAcceptsRelativePathAndSameOriginUrl(): void
    {
        $request = self::request('https://app.local/login');

        self::assertSame('/dashboard', PathUtility::sanitizeReturnTo($request, '/dashboard', '/'));
        self::assertSame('/profile', PathUtility::sanitizeReturnTo($request, 'https://app.local/profile', '/'), 'A same-origin URL comes back as its path');
        self::assertSame('/profile?tab=2#mfa', PathUtility::sanitizeReturnTo($request, 'https://app.local/profile?tab=2#mfa', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, 'https://app.local', '/fallback'));
    }

    public function testSanitizeReturnToRefusesASameOriginUrlWhosePathIsProtocolRelative(): void
    {
        // As a path, `//evil.example/x` would leave the site.
        self::assertSame(
            '/fallback',
            PathUtility::sanitizeReturnTo(self::request('https://app.local/login'), 'https://app.local//evil.example/x', '/fallback')
        );
    }

    public function testSanitizeReturnToFlattensANestedReturnTarget(): void
    {
        $request = self::request('https://app.local/de/login/');
        $nested = 'https://app.local/de/login/?' . http_build_query([
            'tx_workosauth_login' => [
                'action' => 'signUp',
                'controller' => 'Frontend\\Login',
                'returnTo' => 'https://app.local/de/login/?' . http_build_query(['returnTo' => '/deeper']),
            ],
            'cHash' => 'e3b0c44298fc1c149afbf4c8996fb924',
        ]);

        self::assertSame('/de/login/', PathUtility::sanitizeReturnTo($request, $nested, '/'));
    }

    public function testSanitizeReturnToRefusesTargetsOverTheLengthLimit(): void
    {
        $request = self::request('https://app.local/login');
        $longest = '/' . str_repeat('a', PathUtility::MAX_RETURN_TO_LENGTH - 1);

        self::assertSame($longest, PathUtility::sanitizeReturnTo($request, $longest, '/fallback'));
        self::assertSame('/fallback', PathUtility::sanitizeReturnTo($request, $longest . 'a', '/fallback'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalReturnTargetProvider(): array
    {
        return [
            'plain path' => ['/de/login/', '/de/login/'],
            'nested return target' => ['/login/?returnTo=%2Fmembers%2F', '/login/'],
            'plugin toggle of 2.3.1' => [
                '/login/?tx_workosauth_login%5Baction%5D=signUp&tx_workosauth_login%5Bcontroller%5D=Frontend%5CLogin'
                . '&tx_workosauth_login%5BreturnTo%5D=https%3A%2F%2Fapp.local%2Flogin%2F&cHash=abc',
                '/login/',
            ],
            'raw brackets' => ['/login/?tx_workosauth_login[returnTo]=/x&tx_workosauth_team[organizationId]=org_1', '/login/'],
            'names PHP rewrites' => ['/login/?tx.workosauth.login%5BreturnTo%5D=%2Fx&%20returnTo=%2Fy', '/login/'],
            'foreign parameter kept' => ['/shop/?page=2&returnTo=%2Fx', '/shop/?page=2'],
            'signed query left intact' => ['/news/?tx_news_pi1%5Bnews%5D=5&cHash=abc', '/news/?tx_news_pi1%5Bnews%5D=5&cHash=abc'],
            'signed query that lost arguments' => ['/news/?tx_news_pi1%5Bnews%5D=5&tx_workosauth_login%5Baction%5D=show&cHash=abc', '/news/'],
            'lone cHash' => ['/page/?cHash=abc', '/page/'],
            'one-shot tokens' => [
                '/typo3/login?loginProvider=1744276800&workosMessage=a&magicAuthState=b&emailVerificationState=c&__RequestToken=d&login_hint=e%40example.com',
                '/typo3/login?loginProvider=1744276800',
            ],
            'backend route target' => ['/typo3/main?redirect=workos_users', '/typo3/main?redirect=workos_users'],
            'fragment kept' => ['/page/?returnTo=%2Fx#section', '/page/#section'],
            'empty pairs dropped' => ['/page/?&a=1&&', '/page/?a=1'],
            'relative path' => ['login/', ''],
            'protocol-relative' => ['//evil.example/x', ''],
            'backslash variant' => ['/\\evil.example/x', ''],
        ];
    }

    #[DataProvider('canonicalReturnTargetProvider')]
    public function testCanonicalReturnTarget(string $target, string $expected): void
    {
        self::assertSame($expected, PathUtility::canonicalReturnTarget($target));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function toggleProvider(): array
    {
        return [
            'no target' => ['https://app.local/de/login/', ''],
            'a requested target' => ['https://app.local/de/login/?returnTo=' . rawurlencode('/de/members/?tab=2'), '/de/members/?tab=2'],
            'the login page as target' => ['https://app.local/de/login/?returnTo=%2Fde%2Flogin', ''],
        ];
    }

    #[DataProvider('toggleProvider')]
    public function testTogglingAnyNumberOfTimesYieldsTheSameReturnTarget(string $url, string $expected): void
    {
        // Mirrors the Login plugin: the requested target is the plugin
        // argument of the current URL, else its plain `returnTo`; a link
        // carries it only when there is one.
        $lengths = [];
        for ($toggle = 0; $toggle < 20; $toggle++) {
            $request = self::request($url);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $arguments = is_array($query['tx_workosauth_login'] ?? null) ? $query['tx_workosauth_login'] : [];
            $candidate = $arguments['returnTo'] ?? $query['returnTo'] ?? '';
            $returnTo = PathUtility::requestedReturnTarget($request, is_string($candidate) ? $candidate : '');
            self::assertSame($expected, $returnTo);

            $action = $toggle % 2 === 0 ? 'signUp' : 'show';
            $url = 'https://app.local/de/login/?' . http_build_query([
                'tx_workosauth_login' => ['action' => $action, 'controller' => 'Frontend\\Login']
                    + ($returnTo !== '' ? ['returnTo' => $returnTo] : []),
                'cHash' => hash('sha256', (string)$toggle),
            ]);
            $lengths[$action][] = strlen($url);
        }

        self::assertCount(1, array_unique($lengths['signUp']), 'Toggle N times, same length as toggling once');
        self::assertCount(1, array_unique($lengths['show']), 'Toggle N times, same length as toggling once');
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function requestedReturnTargetProvider(): array
    {
        return [
            'nothing requested' => ['https://app.local/de/login/', null, ''],
            'blank' => ['https://app.local/de/login/', '  ', ''],
            'another page' => ['https://app.local/de/login/', '/de/members/?tab=2#list', '/de/members/?tab=2#list'],
            'same-origin URL as its path' => ['https://app.local/de/login/', 'https://app.local/de/members/', '/de/members/'],
            'the login page' => ['https://app.local/de/login/', '/de/login/', ''],
            'the login page without trailing slash' => ['https://app.local/de/login/', '/de/login', ''],
            'the login page with a fragment' => ['https://app.local/de/login/', '/de/login/#form', ''],
            'the login page as same-origin URL' => ['https://app.local/de/login/', 'https://app.local/de/login', ''],
            'the login page, nested by 2.3.1' => [
                'https://app.local/de/login/',
                'https://app.local/de/login/?' . http_build_query(['tx_workosauth_login' => ['action' => 'signUp', 'returnTo' => '/deeper']]),
                '',
            ],
            'the login page seen from its form action' => [
                'https://app.local/de/login/?tx_workosauth_login%5Baction%5D=passwordAuth&tx_workosauth_login%5Bcontroller%5D=Frontend%5CLogin&cHash=abc',
                '/de/login',
                '',
            ],
            'the login page with another query' => ['https://app.local/de/login/', '/de/login/?campaign=spring', '/de/login/?campaign=spring'],
            'the site root is a page of its own' => ['https://app.local/de/login/', '/', '/'],
            'a page below the login page' => ['https://app.local/de/login/', '/de/login/help', '/de/login/help'],
            'foreign host' => ['https://app.local/de/login/', 'https://evil.example/de/login/', ''],
            'protocol-relative' => ['https://app.local/de/login/', '//evil.example/', ''],
            'over the length limit' => ['https://app.local/de/login/', '/' . str_repeat('a', PathUtility::MAX_RETURN_TO_LENGTH), ''],
        ];
    }

    #[DataProvider('requestedReturnTargetProvider')]
    public function testRequestedReturnTarget(string $currentUrl, ?string $candidate, string $expected): void
    {
        self::assertSame($expected, PathUtility::requestedReturnTarget(self::request($currentUrl), $candidate));
    }

    public function testCurrentPageReturnTarget(): void
    {
        self::assertSame('/de/login/', PathUtility::currentPageReturnTarget(self::request(
            'https://app.local/de/login/?tx_workosauth_login%5Baction%5D=show&tx_workosauth_login%5BreturnTo%5D=%2Fx&cHash=abc'
        )));
        self::assertSame('/shop/?page=2', PathUtility::currentPageReturnTarget(self::request('https://app.local/shop/?page=2&returnTo=%2Fx')));
        self::assertSame('/', PathUtility::currentPageReturnTarget(self::request('https://app.local')));
        self::assertSame('/', PathUtility::currentPageReturnTarget(self::request('https://app.local//evil.example/')));
        self::assertSame(
            '/shop/',
            PathUtility::currentPageReturnTarget(self::request('https://app.local/shop/?q=' . str_repeat('a', PathUtility::MAX_RETURN_TO_LENGTH))),
            'A query over the limit is dropped, the page stays'
        );
    }

    public function testBackendRouteReturnTarget(): void
    {
        self::assertSame('/typo3/main?redirect=workos_users', PathUtility::backendRouteReturnTarget('/typo3', 'workos_users'));
        self::assertSame(
            '/typo3/main?redirect=web_layout&redirectParams=id%3D1%26x%5By%5D%3D2',
            PathUtility::backendRouteReturnTarget('/typo3/', 'web_layout', 'id=1&x[y]=2')
        );
    }

    public function testSanitizeReturnToRejectsForeignOrigins(): void
    {
        $request = self::request('https://app.local/login');

        self::assertSame('/', PathUtility::sanitizeReturnTo($request, 'https://evil.example/profile', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, 'http://app.local/profile', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, 'https://app.local:8443/profile', '/'));
    }

    public function testSanitizeReturnToFallsBackOnEmpty(): void
    {
        $request = self::request('https://app.local/login');

        self::assertSame('/', PathUtility::sanitizeReturnTo($request, '', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, '   ', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, null, '  '));
    }

    public function testJoinBaseUrlAndPathAbsoluteUrl(): void
    {
        self::assertSame(
            'https://app.local/workos-auth/frontend/callback',
            PathUtility::joinBaseUrlAndPath('https://app.local', '/workos-auth/frontend/callback')
        );
        self::assertSame(
            'https://app.local/workos-auth/frontend/callback',
            PathUtility::joinBaseUrlAndPath('https://app.local/', 'workos-auth/frontend/callback')
        );
    }

    public function testGetPathRelativeToSiteBase(): void
    {
        self::assertSame('/login', PathUtility::getPathRelativeToSiteBase('/de/login', '/de'));
        self::assertSame('/', PathUtility::getPathRelativeToSiteBase('/de', '/de'));
        self::assertSame('/login', PathUtility::getPathRelativeToSiteBase('/login', '/'));
        self::assertSame('/other/foo', PathUtility::getPathRelativeToSiteBase('/other/foo', '/de'));
        self::assertSame('/delta/foo', PathUtility::getPathRelativeToSiteBase('/delta/foo', '/de'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guessBackendBasePathProvider(): array
    {
        return [
            'under /module/' => ['/typo3/module/workos/setup', '/typo3'],
            'under /login' => ['/typo3/login', '/typo3'],
            'under /main' => ['/typo3/main', '/typo3'],
            'under /logout' => ['/typo3/logout', '/typo3'],
            'no marker' => ['/some/path', '/some/path'],
        ];
    }

    #[DataProvider('guessBackendBasePathProvider')]
    public function testGuessBackendBasePath(string $path, string $expected): void
    {
        self::assertSame($expected, PathUtility::guessBackendBasePath($path));
    }

    public function testGuessBasePathFromMatchedPath(): void
    {
        self::assertSame('/typo3', PathUtility::guessBasePathFromMatchedPath('/typo3/workos-auth/backend/login', '/workos-auth/backend/login'));
        self::assertSame('', PathUtility::guessBasePathFromMatchedPath('/workos-auth/backend/login', '/workos-auth/backend/login'));
        self::assertSame('/typo3', PathUtility::guessBasePathFromMatchedPath('/typo3/login', '/'));
    }

    public function testBuildAbsoluteUrlFromRequest(): void
    {
        self::assertSame('https://app.local/callback', PathUtility::buildAbsoluteUrlFromRequest(self::request('https://app.local/login'), '/callback'));
        self::assertSame('https://app.local/callback', PathUtility::buildAbsoluteUrlFromRequest(self::request('https://app.local:443/login'), '/callback'));
        self::assertSame('https://app.local:8443/callback', PathUtility::buildAbsoluteUrlFromRequest(self::request('https://app.local:8443/login'), 'callback'));
        self::assertSame('/callback', PathUtility::buildAbsoluteUrlFromRequest(self::request('/login'), '/callback'));
    }

    public function testOriginFromRequest(): void
    {
        self::assertSame('https://app.local', PathUtility::originFromRequest(self::request('https://App.Local:443/typo3/module')));
        self::assertSame('http://app.local:8080', PathUtility::originFromRequest(self::request('http://app.local:8080/')));
        self::assertSame('', PathUtility::originFromRequest(self::request('/relative')));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeOriginProvider(): array
    {
        return [
            'strips path and lowercases' => ['https://Example.test/foo', 'https://example.test'],
            'keeps non-default port' => ['http://app.test:8080/path', 'http://app.test:8080'],
            'drops default port' => ['https://example.test:443', 'https://example.test'],
            'rejects unsupported scheme' => ['ftp://example.test', ''],
            'rejects host-less value' => ['not-an-origin', ''],
            'rejects empty' => ['  ', ''],
        ];
    }

    #[DataProvider('normalizeOriginProvider')]
    public function testNormalizeOrigin(string $input, string $expected): void
    {
        self::assertSame($expected, PathUtility::normalizeOrigin($input));
    }

    public function testSiteBaseUrlUsesTheSiteHostWhenPresent(): void
    {
        $site = new Site('main', 1, ['base' => 'https://www.example.test/']);

        self::assertSame('https://www.example.test', PathUtility::siteBaseUrl($site, self::request('https://backend.local/typo3/module')));
    }

    public function testSiteBaseUrlFallsBackToTheRequestOriginForPathOnlyBases(): void
    {
        $site = new Site('camino', 2, ['base' => '/camino/']);

        self::assertSame('https://backend.local/camino', PathUtility::siteBaseUrl($site, self::request('https://backend.local/typo3/module')));
    }

    private static function request(string $uri): ServerRequest
    {
        return new ServerRequest(new Uri($uri));
    }
}
