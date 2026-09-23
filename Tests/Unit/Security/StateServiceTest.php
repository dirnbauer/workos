<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\WorkosAuth\Security\StateService;

final class StateServiceTest extends TestCase
{
    private StateService $stateService;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $entries = [];
        $cache = self::createStub(FrontendInterface::class);
        $cache->method('set')->willReturnCallback(
            static function (string $entryIdentifier, mixed $data) use (&$entries): void {
                $entries[$entryIdentifier] = $data;
            }
        );
        $cache->method('get')->willReturnCallback(
            static function (string $entryIdentifier) use (&$entries): mixed {
                return $entries[$entryIdentifier] ?? false;
            }
        );
        $cache->method('remove')->willReturnCallback(
            static function (string $entryIdentifier) use (&$entries): bool {
                unset($entries[$entryIdentifier]);
                return true;
            }
        );

        $cacheManager = self::createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        $this->stateService = new StateService($cacheManager);
    }

    public function testIssuedTokenCanBeConsumed(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', ['returnTo' => '/welcome']);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());

        $payload = $this->stateService->consume(self::callbackRequest($cookie->getName(), (string)$cookie->getValue()), 'frontend', $issued['token']);

        self::assertSame(['returnTo' => '/welcome'], $payload);
    }

    public function testExistingBindingCookieIsReusedWithoutIssuingANewOne(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', []);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);

        $second = $this->stateService->issue(
            self::request('/workos-auth/login')->withCookieParams([$cookie->getName() => $cookie->getValue()]),
            'frontend',
            '/',
            ['returnTo' => '/second']
        );

        self::assertNull($second['cookie']);
        self::assertSame(
            ['returnTo' => '/second'],
            $this->stateService->consume(self::callbackRequest($cookie->getName(), (string)$cookie->getValue()), 'frontend', $second['token'])
        );
    }

    public function testTokenBoundToDifferentBrowserCookieIsRejected(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', ['returnTo' => '/']);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277404);
        $this->stateService->consume(self::callbackRequest($cookie->getName(), 'different-secret'), 'frontend', $issued['token']);
    }

    public function testTokenIssuedForAnotherContextIsRejected(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', []);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277407);
        $this->stateService->consume(self::callbackRequest($cookie->getName(), (string)$cookie->getValue()), 'backend', $issued['token']);
    }

    public function testTokenIsSingleUse(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', ['returnTo' => '/']);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);
        $callbackRequest = self::callbackRequest($cookie->getName(), (string)$cookie->getValue());

        $this->stateService->consume($callbackRequest, 'frontend', $issued['token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277402);
        $this->stateService->consume($callbackRequest, 'frontend', $issued['token']);
    }

    public function testPeekKeepsTokenAvailableUntilConsumed(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', ['returnTo' => '/welcome']);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);
        $callbackRequest = self::callbackRequest($cookie->getName(), (string)$cookie->getValue());

        self::assertSame(['returnTo' => '/welcome'], $this->stateService->peek($callbackRequest, 'frontend', $issued['token']));
        self::assertSame(['returnTo' => '/welcome'], $this->stateService->consume($callbackRequest, 'frontend', $issued['token']));
    }

    public function testRemoveInvalidatesIssuedToken(): void
    {
        $issued = $this->stateService->issue(self::request('/workos-auth/login'), 'frontend', '/', ['returnTo' => '/']);
        $cookie = $issued['cookie'];
        self::assertNotNull($cookie);

        $this->stateService->remove($issued['token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277402);
        $this->stateService->peek(self::callbackRequest($cookie->getName(), (string)$cookie->getValue()), 'frontend', $issued['token']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTokenProvider(): array
    {
        return [
            'empty' => [''],
            'free text' => ['Your account is locked, call +43 1 234567'],
            'markup' => ['<script>alert(1)</script>'],
            'upper-case hex' => [str_repeat('A', 64)],
            'too short' => [str_repeat('a', 63)],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    /**
     * Tokens come from URLs and form fields: anything but the issued format
     * must end as the flow's own "invalid state" error, not reach the cache
     * backend, which rejects odd identifiers with an exception of its own.
     */
    #[DataProvider('malformedTokenProvider')]
    public function testMalformedTokensAreRejectedBeforeTheCacheSeesThem(string $token): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277401);

        $this->stateService->peek(self::request('/typo3/login'), 'backend_login_message', $token);
    }

    public function testCallbackStateWrappedInJsonIsUnwrapped(): void
    {
        $wrapped = json_encode(['token' => 'raw-token'], JSON_THROW_ON_ERROR);
        self::assertSame('raw-token', $this->stateService->extractTokenFromCallbackState($wrapped));
    }

    public function testCallbackStateWithoutTokenIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277406);
        $this->stateService->extractTokenFromCallbackState('raw-token');
    }

    public function testEmptyCallbackStateIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277405);
        $this->stateService->extractTokenFromCallbackState('  ');
    }

    private static function request(string $path): ServerRequest
    {
        return new ServerRequest(new Uri('https://app.local' . $path));
    }

    private static function callbackRequest(string $cookieName, string $cookieValue): ServerRequest
    {
        return self::request('/workos-auth/callback')->withCookieParams([$cookieName => $cookieValue]);
    }
}
