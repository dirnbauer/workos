<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebConsulting\WorkosAuth\Security\WorkosErrorMessageResolver;

final class WorkosErrorMessageResolverTest extends TestCase
{
    private WorkosErrorMessageResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new WorkosErrorMessageResolver();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authenticationProvider(): array
    {
        return [
            'invalid credentials' => ['Invalid credentials provided', 'error.invalidEmailOrPassword'],
            'unauthorized' => ['Unauthorized access', 'error.invalidEmailOrPassword'],
            'magic auth disabled' => ['Magic auth is not enabled for this project', 'error.magicAuthDisabled'],
            'method not allowed' => ['authentication_method_not_allowed', 'error.methodNotAllowed'],
            'expired code' => ['The code has expired', 'error.invalidOrExpiredCode'],
            'invalid code' => ['Invalid verification code', 'error.invalidOrExpiredCode'],
            'user not found' => ['user_not_found', 'error.userNotFound'],
            'generic fallback' => ['Something unexpected happened', 'error.generic'],
        ];
    }

    #[DataProvider('authenticationProvider')]
    public function testResolveAuthentication(string $message, string $expectedKey): void
    {
        self::assertSame($expectedKey, $this->resolver->resolveAuthentication($message));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function signUpProvider(): array
    {
        return [
            'password too short' => ['password_too_short', 'error.passwordTooShort'],
            'password too weak' => ['Password is too weak and unguessable', 'error.passwordTooWeak'],
            'breached password' => ['Password found in a pwned database', 'error.passwordBreached'],
            'duplicate user' => ['A user with this email already exists', 'error.userAlreadyExists'],
            'invalid password' => ['The password field is invalid', 'error.passwordInvalid'],
            'generic fallback' => ['Unexpected upstream failure', 'error.generic'],
        ];
    }

    #[DataProvider('signUpProvider')]
    public function testResolveSignUp(string $message, string $expectedKey): void
    {
        self::assertSame($expectedKey, $this->resolver->resolveSignUp($message));
    }
}
