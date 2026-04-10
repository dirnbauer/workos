<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class StateService
{
    private const TTL = 600;

    public function issue(array $payload): string
    {
        $payload['issuedAt'] = $payload['issuedAt'] ?? time();
        $encodedPayload = $this->base64UrlEncode((string)json_encode($payload, JSON_THROW_ON_ERROR));
        $hmac = GeneralUtility::hmac($encodedPayload, self::class);

        return $encodedPayload . '.' . $hmac;
    }

    public function consume(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid WorkOS state token format.', 1744277401);
        }

        [$encodedPayload, $givenHmac] = $parts;
        $expectedHmac = GeneralUtility::hmac($encodedPayload, self::class);
        if (!hash_equals($expectedHmac, $givenHmac)) {
            throw new \RuntimeException('The WorkOS state token could not be verified.', 1744277402);
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('The WorkOS state payload is invalid.', 1744277403);
        }

        $issuedAt = (int)($payload['issuedAt'] ?? 0);
        if ($issuedAt <= 0 || (time() - $issuedAt) > self::TTL) {
            throw new \RuntimeException('The WorkOS state token has expired.', 1744277404);
        }

        return $payload;
    }

    public function extractTokenFromCallbackState(string $rawState): string
    {
        $rawState = trim($rawState);
        if ($rawState === '') {
            throw new \RuntimeException('Missing WorkOS state parameter.', 1744277405);
        }

        $decoded = json_decode($rawState, true);
        if (is_array($decoded)) {
            $token = trim((string)($decoded['token'] ?? ''));
            if ($token === '') {
                throw new \RuntimeException('Missing WorkOS state token.', 1744277406);
            }
            return $token;
        }

        return $rawState;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string)base64_decode(strtr($value, '-_', '+/'));
    }
}
