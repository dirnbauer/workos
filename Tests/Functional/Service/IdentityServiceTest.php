<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Functional\Service;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebConsulting\WorkosAuth\Service\IdentityService;

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
            context: 'frontend',
            workosUserId: 'user_01TEST',
            email: 'alice@example.com',
            userTable: 'fe_users',
            userUid: 42,
            workosProfile: ['id' => 'user_01TEST', 'email' => 'alice@example.com'],
        );

        $row = $service->findIdentity('frontend', 'user_01TEST');
        self::assertIsArray($row);
        self::assertSame('alice@example.com', $row['email']);
        self::assertSame('fe_users', $row['user_table']);
        self::assertSame(42, is_numeric($row['user_uid']) ? (int)$row['user_uid'] : 0);
    }

    public function testStoreTwiceUpdatesInsteadOfDuplicating(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity('frontend', 'user_02', 'old@example.com', 'fe_users', 1);
        $service->storeIdentity('frontend', 'user_02', 'new@example.com', 'fe_users', 2);

        $row = $service->findIdentity('frontend', 'user_02');
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

        $service->storeIdentity('backend', 'user_03', 'admin@example.com', 'be_users', 7);

        $row = $service->findIdentityByLocalUser('backend', 'be_users', 7);
        self::assertIsArray($row);
        self::assertSame('user_03', $row['workos_user_id']);

        self::assertNull($service->findIdentityByLocalUser('backend', 'be_users', 999));
    }

    public function testFindProfileByLocalUserReturnsDecodedArray(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        $service->storeIdentity(
            context: 'frontend',
            workosUserId: 'user_04',
            email: 'bob@example.com',
            userTable: 'fe_users',
            userUid: 5,
            workosProfile: ['email' => 'bob@example.com', 'firstName' => 'Bob'],
        );

        $profile = $service->findProfileByLocalUser('frontend', 'fe_users', 5);
        self::assertIsArray($profile);
        self::assertSame('bob@example.com', $profile['email']);
        self::assertSame('Bob', $profile['firstName']);
    }

    public function testFindProfileReturnsNullWhenNoRecordExists(): void
    {
        $service = $this->get(IdentityService::class);
        self::assertInstanceOf(IdentityService::class, $service);

        self::assertNull($service->findProfileByLocalUser('frontend', 'fe_users', 99999));
    }
}
