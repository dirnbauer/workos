<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Exception\AccountNotLinkedException;
use Webconsulting\WorkosAuth\Exception\ImpersonationNotAllowedException;
use Webconsulting\WorkosAuth\Security\WorkosErrorMessageResolver;

final class WorkosErrorMessageResolverTest extends TestCase
{
    private WorkosErrorMessageResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function passwordChangeProvider(): array
    {
        return [
            'too short' => ['password_too_short', 'account.flash.passwordTooShort'],
            'too weak' => ['too weak', 'account.flash.passwordTooWeak'],
            'breached' => ['compromised password', 'account.flash.passwordBreached'],
            'anything else' => ['The password field is invalid', 'account.flash.passwordFailed'],
        ];
    }

    #[DataProvider('passwordChangeProvider')]
    public function testResolvePasswordChange(string $message, string $expectedKey): void
    {
        self::assertSame($expectedKey, $this->resolver->resolvePasswordChange($message));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invitationProvider(): array
    {
        return [
            'forbidden organization' => ['forbidden_organization', 'team.flash.forbidden'],
            'already invited' => ['User already invited', 'team.flash.inviteAlreadyExists'],
            'already exists' => ['Member already exists', 'team.flash.inviteAlreadyExists'],
            'invalid email' => ['invalid_email', 'team.flash.inviteInvalidEmail'],
            'generic fallback' => ['boom', 'team.flash.inviteFailed'],
        ];
    }

    #[DataProvider('invitationProvider')]
    public function testResolveInvitation(string $message, string $expectedKey): void
    {
        self::assertSame($expectedKey, $this->resolver->resolveInvitation($message));
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function loginFailureProvider(): array
    {
        return [
            'account not linked' => [
                new AccountNotLinkedException(LoginContext::Backend, 'a@example.com', 'user_01', 'No backend user matched', 1744277605),
                'error.accountNotLinked',
            ],
            'impersonation' => [new ImpersonationNotAllowedException('refused', 1758200002), 'error.impersonationNotAllowed'],
            'domain not allowed' => [new \RuntimeException('not allowed', 1744277608), 'error.domainNotAllowed'],
            'unverified email, linking' => [new \RuntimeException('not verified', 1744277609), 'error.emailNotVerified'],
            'unverified email, creating' => [new \RuntimeException('not verified', 1744277611), 'error.emailNotVerified'],
            'linked account disabled' => [new \RuntimeException('disabled', 1744277610), 'error.accountDisabled'],
            'ambiguous email' => [new \RuntimeException('ambiguous', 1744277612), 'error.accountAmbiguous'],
            'sdk error by message' => [new \RuntimeException('Invalid credentials provided'), 'error.invalidEmailOrPassword'],
            'anything else' => [new \LogicException('boom'), 'error.generic'],
        ];
    }

    #[DataProvider('loginFailureProvider')]
    public function testResolveLogin(\Throwable $exception, string $expectedKey): void
    {
        self::assertSame($expectedKey, $this->resolver->resolveLogin($exception));
    }
}
