<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use WebConsulting\WorkosAuth\Security\StateService;

final class StateServiceTest extends TestCase
{
    private StateService $stateService;
    private FrontendInterface&MockObject $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $entries = [];
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->cache->method('set')->willReturnCallback(
            static function (string $entryIdentifier, mixed $data) use (&$entries): void {
                $entries[$entryIdentifier] = $data;
            }
        );
        $this->cache->method('get')->willReturnCallback(
            static function (string $entryIdentifier) use (&$entries): mixed {
                return $entries[$entryIdentifier] ?? false;
            }
        );
        $this->cache->method('remove')->willReturnCallback(
            static function (string $entryIdentifier) use (&$entries): bool {
                unset($entries[$entryIdentifier]);
                return true;
            }
        );

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('workos_auth_state')->willReturn($this->cache);

        $this->stateService = new StateService($cacheManager);
    }

    public function testIssuedTokenCanBeConsumed(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/workos-auth/login'));
        $issued = $this->stateService->issue($request, 'frontend', '/', ['returnTo' => '/welcome']);
        $token = $issued['token'];
        $cookie = $issued['cookie'];

        self::assertNotNull($cookie);

        $callbackRequest = (new ServerRequest(new Uri('https://app.local/workos-auth/callback')))
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);

        $payload = $this->stateService->consume($callbackRequest, 'frontend', $token);

        self::assertSame('/welcome', $payload['returnTo']);
    }

    public function testTokenBoundToDifferentBrowserCookieIsRejected(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/workos-auth/login'));
        $issued = $this->stateService->issue($request, 'frontend', '/', ['returnTo' => '/']);
        $token = $issued['token'];
        $cookie = $issued['cookie'];

        self::assertNotNull($cookie);

        $callbackRequest = (new ServerRequest(new Uri('https://app.local/workos-auth/callback')))
            ->withCookieParams([$cookie->getName() => 'different-secret']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277404);
        $this->stateService->consume($callbackRequest, 'frontend', $token);
    }

    public function testTokenIsSingleUse(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/workos-auth/login'));
        $issued = $this->stateService->issue($request, 'frontend', '/', ['returnTo' => '/']);
        $cookie = $issued['cookie'];

        self::assertNotNull($cookie);

        $callbackRequest = (new ServerRequest(new Uri('https://app.local/workos-auth/callback')))
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);

        $this->stateService->consume($callbackRequest, 'frontend', $issued['token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277402);
        $this->stateService->consume($callbackRequest, 'frontend', $issued['token']);
    }

    public function testPeekKeepsTokenAvailableUntilConsumed(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/workos-auth/login'));
        $issued = $this->stateService->issue($request, 'frontend', '/', ['returnTo' => '/welcome']);
        $cookie = $issued['cookie'];

        self::assertNotNull($cookie);

        $callbackRequest = (new ServerRequest(new Uri('https://app.local/workos-auth/callback')))
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);

        $peekedPayload = $this->stateService->peek($callbackRequest, 'frontend', $issued['token']);
        $consumedPayload = $this->stateService->consume($callbackRequest, 'frontend', $issued['token']);

        self::assertSame('/welcome', $peekedPayload['returnTo'] ?? '/welcome');
        self::assertSame('/welcome', $consumedPayload['returnTo'] ?? '/welcome');
    }

    public function testRemoveInvalidatesIssuedToken(): void
    {
        $request = new ServerRequest(new Uri('https://app.local/workos-auth/login'));
        $issued = $this->stateService->issue($request, 'frontend', '/', ['returnTo' => '/']);
        $cookie = $issued['cookie'];

        self::assertNotNull($cookie);

        $callbackRequest = (new ServerRequest(new Uri('https://app.local/workos-auth/callback')))
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);

        $this->stateService->remove($issued['token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277402);
        $this->stateService->peek($callbackRequest, 'frontend', $issued['token']);
    }

    public function testCallbackStateWrappedInJsonIsUnwrapped(): void
    {
        $wrapped = json_encode(['token' => 'raw-token'], JSON_THROW_ON_ERROR);
        self::assertSame('raw-token', $this->stateService->extractTokenFromCallbackState($wrapped));
    }

    public function testRawCallbackStateIsReturnedUnchanged(): void
    {
        self::assertSame('raw-token', $this->stateService->extractTokenFromCallbackState('raw-token'));
    }
}
