<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;
use WebConsulting\WorkosAuth\Security\StateService;

final class StateServiceTest extends TestCase
{
    private StateService $stateService;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var array<string, mixed> $confVars */
        $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $sys = is_array($confVars['SYS'] ?? null) ? $confVars['SYS'] : [];
        $sys['encryptionKey'] = str_repeat('a', 96);
        $confVars['SYS'] = $sys;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
        $this->stateService = new StateService(new HashService());
    }

    public function testIssuedTokenCanBeConsumed(): void
    {
        $token = $this->stateService->issue([
            'context' => 'frontend',
            'returnTo' => '/welcome',
            'issuedAt' => time(),
        ]);

        $payload = $this->stateService->consume($token);

        self::assertSame('frontend', $payload['context']);
        self::assertSame('/welcome', $payload['returnTo']);
    }

    public function testTokenWithInvalidSignatureIsRejected(): void
    {
        $token = $this->stateService->issue(['context' => 'frontend', 'returnTo' => '/']);
        [$encodedPayload] = explode('.', $token, 2);
        $tampered = $encodedPayload . '.' . bin2hex(random_bytes(16));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277402);
        $this->stateService->consume($tampered);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->stateService->issue([
            'context' => 'frontend',
            'returnTo' => '/',
            'issuedAt' => time() - 3600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277404);
        $this->stateService->consume($token);
    }

    public function testCallbackStateWrappedInJsonIsUnwrapped(): void
    {
        $wrapped = json_encode(['token' => 'raw-token'], JSON_THROW_ON_ERROR);
        self::assertSame('raw-token', $this->stateService->extractTokenFromCallbackState($wrapped));
    }

    public function testRawCallbackStateIsReturnedUnchanged(): void
    {
        self::assertSame('raw-token', $this->stateService->extractTokenFromCallbackState('raw-token'));
    }
}
