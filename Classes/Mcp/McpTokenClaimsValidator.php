<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

final class McpTokenClaimsValidator
{
    /**
     * @param array<string, mixed> $claims
     */
    public function isValid(array $claims, string $expectedIssuer, string $expectedAudience): bool
    {
        $issuer = $claims['iss'] ?? null;
        if (!is_string($issuer) || rtrim($issuer, '/') !== rtrim($expectedIssuer, '/')) {
            return false;
        }

        if (!$this->audienceMatches($claims['aud'] ?? null, $expectedAudience)) {
            return false;
        }

        $expiresAt = $claims['exp'] ?? null;
        return (is_int($expiresAt) || is_float($expiresAt)) && $expiresAt > time();
    }

    private function audienceMatches(mixed $audience, string $expectedAudience): bool
    {
        if (is_string($audience)) {
            return hash_equals($expectedAudience, $audience);
        }

        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $candidate) {
            if (is_string($candidate) && hash_equals($expectedAudience, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
