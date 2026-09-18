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
        self::assertSame('https://app.local/profile', PathUtility::sanitizeReturnTo($request, 'https://app.local/profile', '/'));
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
