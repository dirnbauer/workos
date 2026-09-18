<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Domain\LoginContext;

final class LoginContextTest extends TestCase
{
    public function testFrontendMapsToFeUsersAndCoreTokenScope(): void
    {
        self::assertSame('frontend', LoginContext::Frontend->value);
        self::assertSame('fe_users', LoginContext::Frontend->userTable());
        self::assertSame('logintype', LoginContext::Frontend->loginStatusField());
        self::assertSame('core/user-auth/fe', LoginContext::Frontend->coreRequestTokenScope());
        self::assertSame('fe', LoginContext::Frontend->loginTypeAbbreviation());
    }

    public function testBackendMapsToBeUsersAndCoreTokenScope(): void
    {
        self::assertSame('backend', LoginContext::Backend->value);
        self::assertSame('be_users', LoginContext::Backend->userTable());
        self::assertSame('login_status', LoginContext::Backend->loginStatusField());
        self::assertSame('core/user-auth/be', LoginContext::Backend->coreRequestTokenScope());
        self::assertSame('be', LoginContext::Backend->loginTypeAbbreviation());
    }

    /**
     * @return iterable<string, array{string, LoginContext}>
     */
    public static function loginTypeProvider(): iterable
    {
        yield 'auth service mode BE' => ['authUserBE', LoginContext::Backend];
        yield 'auth service mode FE' => ['getUserFE', LoginContext::Frontend];
        yield 'loginType BE' => ['BE', LoginContext::Backend];
        yield 'loginType lowercase fe' => ['fe', LoginContext::Frontend];
        yield 'unknown falls back to frontend' => ['', LoginContext::Frontend];
    }

    #[DataProvider('loginTypeProvider')]
    public function testFromLoginType(string $loginType, LoginContext $expected): void
    {
        self::assertSame($expected, LoginContext::fromLoginType($loginType));
    }
}
