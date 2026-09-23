<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Security;

/**
 * Reads claims from the access token of a WorkOS authentication response.
 *
 * The token is not verified here, and does not need to be: it arrives in
 * the body of the server-to-server code exchange with WorkOS, over TLS and
 * authenticated with the client secret, so its origin is already
 * established (OpenID Connect Core 1.0, section 3.1.3.7, allows the same
 * for ID tokens from the token endpoint). Never use this for a token that
 * came from a browser — the MCP server verifies those against the JWKS.
 */
final class AccessTokenClaims
{
    private function __construct() {}

    /**
     * The WorkOS session id (`sid`), or null when the token carries none.
     */
    public static function sessionId(string $accessToken): ?string
    {
        $sessionId = self::claims($accessToken)['sid'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(string $accessToken): array
    {
        $segments = explode('.', $accessToken);
        if (count($segments) !== 3) {
            return [];
        }

        $json = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if ($json === false) {
            return [];
        }

        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return MixedCaster::stringKeyedArray($claims) ?? [];
    }
}
