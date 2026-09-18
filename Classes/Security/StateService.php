<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Security;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Server-side, single-use state for multi-step login flows (OAuth callback,
 * backend magic auth and email verification). Only a random lookup token is
 * exposed to the browser; it is bound to an HttpOnly cookie so a token
 * cannot be replayed from another browser.
 */
final readonly class StateService
{
    private const string CACHE_IDENTIFIER = 'workos_auth_state';
    private const string COOKIE_PREFIX = 'workos_auth_state_';
    private const int TTL = 600;

    public function __construct(
        private CacheManager $cacheManager,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, cookie: Cookie|null} cookie is set when the browser has no binding cookie yet
     */
    public function issue(ServerRequestInterface $request, string $context, string $cookiePath, array $payload): array
    {
        $bindingCookieName = self::COOKIE_PREFIX . $context;
        $bindingSecret = trim(MixedCaster::string($request->getCookieParams()[$bindingCookieName] ?? null));
        $cookie = null;

        if ($bindingSecret === '') {
            $bindingSecret = bin2hex(random_bytes(32));
            $cookie = new Cookie(
                $bindingCookieName,
                $bindingSecret,
                0,
                $this->normalizeCookiePath($cookiePath),
                null,
                $request->getUri()->getScheme() === 'https',
                true,
                false,
                Cookie::SAMESITE_LAX
            );
        }

        $token = bin2hex(random_bytes(32));
        $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->set($token, [
            'context' => $context,
            'bindingHash' => hash('sha256', $bindingSecret),
            'payload' => $payload,
        ], [], self::TTL);

        return ['token' => $token, 'cookie' => $cookie];
    }

    /**
     * Resolve and invalidate a token.
     *
     * @return array<string, mixed>
     */
    public function consume(ServerRequestInterface $request, string $expectedContext, string $token): array
    {
        return $this->resolve($request, $expectedContext, $token, true);
    }

    /**
     * Resolve a token without invalidating it, so follow-up screens can
     * re-render the flow while keeping the server-side integrity guarantees.
     *
     * @return array<string, mixed>
     */
    public function peek(ServerRequestInterface $request, string $expectedContext, string $token): array
    {
        return $this->resolve($request, $expectedContext, $token, false);
    }

    public function remove(string $token): void
    {
        $token = trim($token);
        if ($token !== '') {
            $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->remove($token);
        }
    }

    /**
     * The OAuth `state` parameter is the JSON document `{"token": "..."}`
     * issued by the authorization URL builder.
     */
    public function extractTokenFromCallbackState(string $rawState): string
    {
        if (trim($rawState) === '') {
            throw new \RuntimeException('Missing WorkOS state parameter.', 1744277405);
        }

        $decoded = json_decode($rawState, true);
        $token = is_array($decoded) ? trim(MixedCaster::string($decoded['token'] ?? null)) : '';
        if ($token === '') {
            throw new \RuntimeException('Missing WorkOS state token.', 1744277406);
        }

        return $token;
    }

    private function normalizeCookiePath(string $cookiePath): string
    {
        $cookiePath = trim($cookiePath);

        return $cookiePath === '' || $cookiePath === '/' ? '/' : '/' . trim($cookiePath, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(ServerRequestInterface $request, string $expectedContext, string $token, bool $consume): array
    {
        if ($token === '') {
            throw new \RuntimeException('Invalid WorkOS state token format.', 1744277401);
        }

        $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        $entry = $cache->get($token);
        if (!is_array($entry)) {
            throw new \RuntimeException('The WorkOS state token could not be verified.', 1744277402);
        }

        $context = $entry['context'] ?? null;
        $bindingHash = $entry['bindingHash'] ?? null;
        $payload = MixedCaster::stringKeyedArray($entry['payload'] ?? null);
        if (!is_string($context) || !is_string($bindingHash) || $payload === null) {
            throw new \RuntimeException('The WorkOS state payload is invalid.', 1744277403);
        }

        if ($context !== $expectedContext) {
            throw new \RuntimeException('The WorkOS callback context did not match the login flow.', 1744277407);
        }

        $receivedBindingSecret = trim(MixedCaster::string($request->getCookieParams()[self::COOKIE_PREFIX . $expectedContext] ?? null));
        if ($receivedBindingSecret === '' || !hash_equals($bindingHash, hash('sha256', $receivedBindingSecret))) {
            throw new \RuntimeException('The WorkOS state token has expired.', 1744277404);
        }

        if ($consume) {
            $cache->remove($token);
        }

        return $payload;
    }
}
