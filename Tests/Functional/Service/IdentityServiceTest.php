<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Service;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Service\IdentityService;

/**
 * Covers the `tx_workosauth_identity` read/write path end-to-end
 * against a real database. Pure-unit tests cannot exercise the
 * INSERT/UPDATE codepath because it depends on TYPO3's ConnectionPool.
 */
final class IdentityServiceTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    public function testStoreThenFindRoundTrips(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(
            context: LoginContext::Frontend,
            workosUserId: 'user_01TEST',
            email: 'alice@example.com',
            userUid: 42,
            workosProfile: ['id' => 'user_01TEST', 'email' => 'alice@example.com'],
        );

        $row = $service->findIdentity(LoginContext::Frontend, 'user_01TEST');
        self::assertIsArray($row);
        self::assertSame('alice@example.com', $row['email']);
        self::assertSame('fe_users', $row['user_table']);
        self::assertSame(42, is_numeric($row['user_uid']) ? (int)$row['user_uid'] : 0);
    }

    public function testStoreTwiceUpdatesInsteadOfDuplicating(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(LoginContext::Frontend, 'user_02', 'old@example.com', 1);
        $service->storeIdentity(LoginContext::Frontend, 'user_02', 'new@example.com', 2);

        $row = $service->findIdentity(LoginContext::Frontend, 'user_02');
        self::assertIsArray($row);
        self::assertSame('new@example.com', $row['email']);
        self::assertSame(2, is_numeric($row['user_uid']) ? (int)$row['user_uid'] : 0);

        $allRows = $this->getConnectionPool()
            ->getConnectionForTable('tx_workosauth_identity')
            ->select(['uid'], 'tx_workosauth_identity', ['workos_user_id' => 'user_02'])
            ->fetchAllAssociative();
        self::assertCount(1, $allRows, 'second storeIdentity() must update, not duplicate');
    }

    public function testFindByLocalUserResolvesCorrectly(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(LoginContext::Backend, 'user_03', 'admin@example.com', 7);

        $row = $service->findIdentityByLocalUser(LoginContext::Backend, 7);
        self::assertIsArray($row);
        self::assertSame('user_03', $row['workos_user_id']);
        self::assertSame('be_users', $row['user_table']);

        self::assertNull($service->findIdentityByLocalUser(LoginContext::Backend, 999));
        self::assertNull($service->findIdentityByLocalUser(LoginContext::Frontend, 7), 'contexts must not leak into each other');
    }

    public function testStoreIdentityRebindsLocalUserToLatestWorkosIdentity(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(LoginContext::Backend, 'user_old', 'admin@example.com', 7);
        $service->storeIdentity(LoginContext::Backend, 'user_new', 'admin@example.com', 7);

        $row = $service->findIdentityByLocalUser(LoginContext::Backend, 7);
        self::assertIsArray($row);
        self::assertSame('user_new', $row['workos_user_id']);

        $allRows = $this->getConnectionPool()
            ->getConnectionForTable('tx_workosauth_identity')
            ->select(['uid', 'workos_user_id'], 'tx_workosauth_identity', [
                'login_context' => 'backend',
                'user_table' => 'be_users',
                'user_uid' => 7,
            ])
            ->fetchAllAssociative();

        self::assertCount(1, $allRows, 'local user must only keep one current WorkOS identity mapping');
        self::assertSame('user_new', $allRows[0]['workos_user_id']);
    }

    public function testFindProfileByLocalUserReturnsDecodedArray(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(
            context: LoginContext::Frontend,
            workosUserId: 'user_04',
            email: 'bob@example.com',
            userUid: 5,
            workosProfile: ['email' => 'bob@example.com', 'firstName' => 'Bob'],
        );

        $profile = $service->findProfileByLocalUser(LoginContext::Frontend, 5);
        self::assertIsArray($profile);
        self::assertSame('bob@example.com', $profile['email']);
        self::assertSame('Bob', $profile['firstName']);
    }

    public function testFindProfileReturnsNullWhenNoRecordExists(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        self::assertNull($service->findProfileByLocalUser(LoginContext::Frontend, 99999));
    }
}
