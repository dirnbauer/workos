<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Domain\AuthenticatedSession;
use WorkOS\Resource\AuthenticateResponse;

final class AuthenticatedSessionTest extends TestCase
{
    public function testARegularSignInIsNotImpersonated(): void
    {
        $response = AuthenticateResponse::fromArray(self::response());
        $session = AuthenticatedSession::fromResponse($response, $response->user, 'session_01');

        self::assertFalse($session->isImpersonated());
        self::assertSame('session_01', $session->sessionId);
        self::assertSame('user_01', $session->user->id);
    }

    public function testTheImpersonatorIsCarriedAlong(): void
    {
        $response = AuthenticateResponse::fromArray(self::response() + [
            'impersonator' => ['email' => 'support@example.com', 'reason' => 'Ticket 42'],
        ]);
        $session = AuthenticatedSession::fromResponse($response, $response->user, null);

        self::assertTrue($session->isImpersonated());
        self::assertSame('support@example.com', $session->impersonatorEmail);
        self::assertSame('Ticket 42', $session->impersonationReason);
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(): array
    {
        return [
            'user' => [
                'object' => 'user',
                'id' => 'user_01',
                'email' => 'editor@example.com',
                'email_verified' => true,
                'first_name' => 'Edi',
                'last_name' => 'Tor',
                'created_at' => '2026-09-01T00:00:00+00:00',
                'updated_at' => '2026-09-01T00:00:00+00:00',
            ],
            'access_token' => 'header.payload.signature',
            'refresh_token' => 'refresh',
        ];
    }
}
