<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Mcp\McpTokenClaimsValidator;

final class McpTokenClaimsValidatorTest extends TestCase
{
    private const ISSUER = 'https://example.authkit.app';
    private const AUDIENCE = 'https://example.test/workos-auth/mcp';

    public function testAcceptsExactIssuerAudienceAndFutureExpiration(): void
    {
        $subject = new McpTokenClaimsValidator();

        self::assertTrue($subject->isValid([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 300,
        ], self::ISSUER, self::AUDIENCE));
    }

    public function testAcceptsExpectedAudienceInAudienceList(): void
    {
        $subject = new McpTokenClaimsValidator();

        self::assertTrue($subject->isValid([
            'iss' => self::ISSUER . '/',
            'aud' => ['https://other.example/mcp', self::AUDIENCE],
            'exp' => time() + 300,
        ], self::ISSUER, self::AUDIENCE));
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('invalidClaimsProvider')]
    public function testRejectsInvalidClaims(array $claims): void
    {
        $subject = new McpTokenClaimsValidator();

        self::assertFalse($subject->isValid($claims, self::ISSUER, self::AUDIENCE));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidClaimsProvider(): iterable
    {
        $valid = [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 300,
        ];

        yield 'missing issuer' => [array_diff_key($valid, ['iss' => true])];
        yield 'different issuer' => [array_replace($valid, ['iss' => 'https://other.authkit.app'])];
        yield 'missing audience' => [array_diff_key($valid, ['aud' => true])];
        yield 'different audience' => [array_replace($valid, ['aud' => 'https://other.example/mcp'])];
        yield 'different audience list' => [array_replace($valid, ['aud' => ['https://other.example/mcp']])];
        yield 'missing expiration' => [array_diff_key($valid, ['exp' => true])];
        yield 'expired' => [array_replace($valid, ['exp' => time() - 1])];
        yield 'string expiration' => [array_replace($valid, ['exp' => (string)(time() + 300)])];
    }
}
