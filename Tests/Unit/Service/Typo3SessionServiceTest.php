<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;

final class Typo3SessionServiceTest extends TestCase
{
    public function testServiceCanBeConstructedWithoutContextDependencies(): void
    {
        $subject = new Typo3SessionService(new NullLogger());
        self::assertSame(Typo3SessionService::class, get_class($subject));
    }
}
