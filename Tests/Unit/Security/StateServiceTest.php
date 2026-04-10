<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use WebConsulting\WorkosAuth\Security\StateService;

final class StateServiceTest extends TestCase
{
    public function testIssuedTokenCanBeConsumed(): void
    {
        $stateService = new StateService();
        $token = $stateService->issue([
            'context' => 'frontend',
            'returnTo' => '/welcome',
            'issuedAt' => time(),
        ]);

        $payload = $stateService->consume($token);

        self::assertSame('frontend', $payload['context']);
        self::assertSame('/welcome', $payload['returnTo']);
    }
}
