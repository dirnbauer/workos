<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Functional\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebConsulting\WorkosAuth\Service\IdentityService;

/**
 * Verifies that identity lookups remain transparent when a workspace
 * aspect is active. The identity table is intentionally live-only
 * (`versioningWS=false`) and reads call `->getRestrictions()->removeAll()`;
 * logging in from inside a workspace preview must resolve the identity
 * without suddenly returning `null`.
 */
final class IdentityServiceWorkspaceTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    public function testFindIdentityWorksUnderWorkspaceAspect(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(
            context: 'frontend',
            workosUserId: 'user_ws_01',
            email: 'alice@example.com',
            userTable: 'fe_users',
            userUid: 11,
        );

        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        $context->setAspect('workspace', new WorkspaceAspect(1));

        $row = $service->findIdentity('frontend', 'user_ws_01');
        self::assertIsArray($row, 'Identity lookup must still succeed when a workspace aspect is active.');
        self::assertSame('alice@example.com', $row['email']);
    }

    public function testFindIdentityByLocalUserWorksUnderWorkspaceAspect(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity('backend', 'user_ws_02', 'admin@example.com', 'be_users', 22);

        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        $context->setAspect('workspace', new WorkspaceAspect(1));

        $row = $service->findIdentityByLocalUser('backend', 'be_users', 22);
        self::assertIsArray($row);
        self::assertSame('user_ws_02', $row['workos_user_id']);
    }
}
