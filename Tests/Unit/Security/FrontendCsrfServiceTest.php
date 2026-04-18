<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Security\FrontendCsrfService;

final class FrontendCsrfServiceTest extends TestCase
{
    private FrontendCsrfService $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var array<string, mixed> $confVars */
        $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $sys = is_array($confVars['SYS'] ?? null) ? $confVars['SYS'] : [];
        $sys['encryptionKey'] = str_repeat('a', 96);
        $confVars['SYS'] = $sys;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
        $GLOBALS['EXEC_TIME'] = time();
        $this->csrf = new FrontendCsrfService(new HashService());
    }

    public function testIssuedTokenValidates(): void
    {
        $user = $this->user('session-abc');
        $token = $this->csrf->issue($user, 'account');

        self::assertTrue($this->csrf->validate($user, 'account', $token));
    }

    public function testDifferentScopeRejectsToken(): void
    {
        $user = $this->user('session-abc');
        $token = $this->csrf->issue($user, 'account');

        self::assertFalse($this->csrf->validate($user, 'team', $token));
    }

    public function testDifferentSessionRejectsToken(): void
    {
        $alice = $this->user('alice-session');
        $bob = $this->user('bob-session');

        $token = $this->csrf->issue($alice, 'account');
        self::assertFalse($this->csrf->validate($bob, 'account', $token));
    }

    public function testEmptyTokenIsRejected(): void
    {
        self::assertFalse($this->csrf->validate($this->user('any'), 'account', ''));
    }

    public function testIssueWithoutSessionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744278200);
        $this->csrf->issue($this->user(''), 'account');
    }

    private function user(string $sessionIdentifier): FrontendUserAuthentication&MockObject
    {
        $user = $this->createMock(FrontendUserAuthentication::class);
        $user->method('getSession')->willReturn(UserSession::createNonFixated($sessionIdentifier));
        return $user;
    }
}
