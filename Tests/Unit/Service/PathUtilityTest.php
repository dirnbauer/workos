<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use WebConsulting\WorkosAuth\Service\PathUtility;

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

        self::assertStringContainsString('returnTo=%2Fdashboard', $url);
        self::assertStringNotContainsString('screen', $url);
        self::assertStringNotContainsString('provider', $url);
    }

    public function testAppendQueryParametersPreservesExistingQuery(): void
    {
        $url = PathUtility::appendQueryParameters('/login?returnTo=%2Fa', ['screen' => 'sign-up']);
        self::assertSame('/login?returnTo=%2Fa&screen=sign-up', $url);
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
        $request = self::request('https://app.local/login');

        self::assertSame(
            '/fallback',
            PathUtility::sanitizeReturnTo($request, $candidate, '/fallback'),
            sprintf('Candidate %s must not be treated as a safe path.', $candidate)
        );
    }

    public function testSanitizeReturnToAcceptsRelativePath(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame('/dashboard', PathUtility::sanitizeReturnTo($request, '/dashboard', '/'));
    }

    public function testSanitizeReturnToAcceptsSameHostAbsoluteUrl(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame(
            'https://app.local/profile',
            PathUtility::sanitizeReturnTo($request, 'https://app.local/profile', '/')
        );
    }

    public function testSanitizeReturnToRejectsDifferentHost(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame(
            '/',
            PathUtility::sanitizeReturnTo($request, 'https://evil.example/profile', '/')
        );
    }

    public function testSanitizeReturnToRejectsDifferentScheme(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame(
            '/',
            PathUtility::sanitizeReturnTo($request, 'http://app.local/profile', '/')
        );
    }

    public function testSanitizeReturnToFallsBackOnEmpty(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, '', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, '   ', '/'));
        self::assertSame('/', PathUtility::sanitizeReturnTo($request, null, '/'));
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
        // unrelated path returns the request path unchanged
        self::assertSame('/other/foo', PathUtility::getPathRelativeToSiteBase('/other/foo', '/de'));
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
        self::assertSame(
            '/typo3',
            PathUtility::guessBasePathFromMatchedPath(
                '/typo3/workos-auth/backend/login',
                '/workos-auth/backend/login'
            )
        );
        self::assertSame(
            '',
            PathUtility::guessBasePathFromMatchedPath(
                '/workos-auth/backend/login',
                '/workos-auth/backend/login'
            )
        );
    }

    public function testBuildAbsoluteUrlFromRequestUsesHostAndScheme(): void
    {
        $request = self::request('https://app.local/login');
        self::assertSame(
            'https://app.local/callback',
            PathUtility::buildAbsoluteUrlFromRequest($request, '/callback')
        );
    }

    public function testBuildAbsoluteUrlFromRequestOmitsDefaultPort(): void
    {
        $request = self::request('https://app.local:443/login');
        self::assertSame(
            'https://app.local/callback',
            PathUtility::buildAbsoluteUrlFromRequest($request, '/callback')
        );
    }

    public function testBuildAbsoluteUrlFromRequestKeepsNonDefaultPort(): void
    {
        $request = self::request('https://app.local:8443/login');
        self::assertSame(
            'https://app.local:8443/callback',
            PathUtility::buildAbsoluteUrlFromRequest($request, '/callback')
        );
    }

    private static function request(string $uri): ServerRequest
    {
        return new ServerRequest(new Uri($uri));
    }
}
