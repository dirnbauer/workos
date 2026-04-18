<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebConsulting\WorkosAuth\Security\SecretRedactor;

final class SecretRedactorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secretProvider(): array
    {
        return [
            'live api key' => [
                'Request failed with sk_live_abcdefgh12345678 at line 42',
                'Request failed with [REDACTED] at line 42',
            ],
            'test api key' => [
                'Using sk_test_qwertyui12345678 for sandbox',
                'Using [REDACTED] for sandbox',
            ],
            'client id' => [
                'client_abcdefghijklmnop was revoked',
                '[REDACTED] was revoked',
            ],
            'bearer token' => [
                'Authorization: Bearer abcDEF123_ghi456-JKL',
                'Authorization: [REDACTED]',
            ],
            'jwt' => [
                'Token expired: eyJhbGciOiJIUzI1NiIs.eyJzdWIiOiIxMjM0NTY3ODkwI.abcdefghijklmn',
                'Token expired: [REDACTED]',
            ],
        ];
    }

    #[DataProvider('secretProvider')]
    public function testRedactsKnownSecretShapes(string $input, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::redact($input));
    }

    public function testLeavesNonSensitiveMessagesUntouched(): void
    {
        $message = 'WorkOS user lookup failed (404) — retry after backoff.';
        self::assertSame($message, SecretRedactor::redact($message));
    }
}
