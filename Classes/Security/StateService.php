<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Cache\CacheManager;

final class StateService
{
    private const CACHE_IDENTIFIER = 'workos_auth_state';
    private const COOKIE_PREFIX = 'workos_auth_state_';
    private const TTL = 600;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{token:string,cookie:Cookie|null}
     */
    public function issue(ServerRequestInterface $request, string $context, string $cookiePath, array $payload): array
    {
        $bindingCookieName = self::COOKIE_PREFIX . $context;
        $bindingCookieValue = $request->getCookieParams()[$bindingCookieName] ?? null;
        $bindingSecret = is_scalar($bindingCookieValue) ? trim((string)$bindingCookieValue) : '';
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
        $cachePayload = [
            'context' => $context,
            'bindingHash' => hash('sha256', $bindingSecret),
            'payload' => $payload,
        ];
        $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->set($token, $cachePayload, [], self::TTL);

        return [
            'token' => $token,
            'cookie' => $cookie,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consume(ServerRequestInterface $request, string $expectedContext, string $token): array
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
        $payload = $entry['payload'] ?? null;

        if (!is_string($context) || !is_string($bindingHash) || !is_array($payload)) {
            throw new \RuntimeException('The WorkOS state payload is invalid.', 1744277403);
        }

        if ($context !== $expectedContext) {
            throw new \RuntimeException('The WorkOS callback context did not match the login flow.', 1744277407);
        }

        $bindingCookieName = self::COOKIE_PREFIX . $expectedContext;
        $receivedBindingCookieValue = $request->getCookieParams()[$bindingCookieName] ?? null;
        $receivedBindingSecret = is_scalar($receivedBindingCookieValue) ? trim((string)$receivedBindingCookieValue) : '';
        if ($receivedBindingSecret === '' || !hash_equals($bindingHash, hash('sha256', $receivedBindingSecret))) {
            throw new \RuntimeException('The WorkOS state token has expired.', 1744277404);
        }

        $cache->remove($token);

        $narrowed = [];
        foreach ($payload as $key => $value) {
            $narrowed[(string)$key] = $value;
        }

        return $narrowed;
    }

    public function extractTokenFromCallbackState(string $rawState): string
    {
        $rawState = trim($rawState);
        if ($rawState === '') {
            throw new \RuntimeException('Missing WorkOS state parameter.', 1744277405);
        }

        $decoded = json_decode($rawState, true);
        if (is_array($decoded)) {
            $rawToken = $decoded['token'] ?? '';
            $token = is_string($rawToken) ? trim($rawToken) : '';
            if ($token === '') {
                throw new \RuntimeException('Missing WorkOS state token.', 1744277406);
            }
            return $token;
        }

        return $rawState;
    }

    private function normalizeCookiePath(string $cookiePath): string
    {
        $cookiePath = trim($cookiePath);
        if ($cookiePath === '' || $cookiePath === '/') {
            return '/';
        }
        return '/' . trim($cookiePath, '/');
    }
}
