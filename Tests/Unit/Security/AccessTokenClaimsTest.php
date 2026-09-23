<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Security\AccessTokenClaims;

final class AccessTokenClaimsTest extends TestCase
{
    public function testTheSessionIdIsReadFromTheSidClaim(): void
    {
        self::assertSame('session_01J', AccessTokenClaims::sessionId(self::token(['sid' => 'session_01J', 'sub' => 'user_01'])));
    }

    public function testBase64UrlEncodedPayloadsAreDecoded(): void
    {
        // A payload whose base64 contains "-" and "_" in its URL-safe form.
        $token = self::token(['sid' => 'session_??>>', 'org_id' => '~~~']);

        self::assertSame('session_??>>', AccessTokenClaims::sessionId($token));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tokensWithoutSession(): array
    {
        return [
            'empty' => [''],
            'two segments' => ['aaa.bbb'],
            'four segments' => ['a.b.c.d'],
            'payload is not base64' => ['header.@@@.signature'],
            'payload is not JSON' => ['header.' . rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=') . '.sig'],
            'payload is a list' => ['header.' . rtrim(strtr(base64_encode('["sid"]'), '+/', '-_'), '=') . '.sig'],
            'no sid claim' => [self::token(['sub' => 'user_01'])],
            'sid is not a string' => [self::token(['sid' => 42])],
            'sid is empty' => [self::token(['sid' => ''])],
        ];
    }

    #[DataProvider('tokensWithoutSession')]
    public function testMalformedTokensYieldNoSession(string $token): void
    {
        self::assertNull(AccessTokenClaims::sessionId($token));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function token(array $claims): string
    {
        $encode = static fn(string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.signature';
    }
}
