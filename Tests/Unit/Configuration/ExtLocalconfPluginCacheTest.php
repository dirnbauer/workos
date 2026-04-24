<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

final class ExtLocalconfPluginCacheTest extends TestCase
{
    public function testFrontendDashboardsAreConfiguredAsUncachedActions(): void
    {
        $configuration = file_get_contents(__DIR__ . '/../../../ext_localconf.php');
        self::assertIsString($configuration);

        self::assertSame(
            2,
            substr_count(
                $configuration,
                "\\WebConsulting\\WorkosAuth\\Controller\\Frontend\\AccountController::class => 'dashboard,updateProfile,changePassword,startMfaEnrollment,verifyMfaEnrollment,cancelMfaEnrollment,deleteFactor,revokeSession'"
            ),
            'Account dashboard must stay uncached because it depends on the current frontend user and session-bound CSRF tokens.'
        );

        self::assertSame(
            2,
            substr_count(
                $configuration,
                "\\WebConsulting\\WorkosAuth\\Controller\\Frontend\\TeamController::class => 'dashboard,invite,resendInvitation,revokeInvitation,launchPortal'"
            ),
            'Team dashboard must stay uncached because it depends on the current frontend user and session-bound CSRF tokens.'
        );
    }
}
