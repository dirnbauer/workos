<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Service;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\UserProvisioningService;
use WorkOS\Resource\User;

/**
 * End-to-end coverage for fe_users / be_users provisioning. Fakes the
 * WorkOS user via the SDK's typed `fromArray()` factory so we can drive
 * the service against a real database.
 */
final class UserProvisioningServiceTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'workos_auth' => [
                'apiKey' => 'sk_test_dummy',
                'clientId' => 'client_dummy',
                'frontendAutoCreateUsers' => '1',
                'frontendLinkByEmail' => '1',
                'frontendStoragePid' => '1',
                'backendAutoCreateUsers' => '0',
                'backendLinkByEmail' => '1',
            ],
        ],
    ];

    public function testResolveFrontendUserCreatesNewRecordAndStoresIdentity(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        $identity = $this->get(IdentityService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);
        self::assertInstanceOf(IdentityService::class, $identity);

        $workosUser = $this->createWorkosUser(
            id: 'user_05',
            email: 'new@example.com',
            firstName: 'New',
            lastName: 'User',
        );

        $result = $provisioning->resolve(LoginContext::Frontend, $workosUser);
        self::assertSame('new@example.com', $result['email']);
        self::assertSame('New User', $result['name']);

        $mapping = $identity->findIdentity(LoginContext::Frontend, 'user_05');
        self::assertIsArray($mapping);
        $resultUid = is_numeric($result['uid']) ? (int)$result['uid'] : 0;
        $mappingUid = is_numeric($mapping['user_uid']) ? (int)$mapping['user_uid'] : 0;
        self::assertSame($resultUid, $mappingUid);
    }

    public function testResolveFrontendUserLinksExistingUserByEmail(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);

        $this->getConnectionPool()->getConnectionForTable('fe_users')->insert('fe_users', [
            'pid' => 1,
            'username' => 'preexisting',
            'email' => 'existing@example.com',
            'password' => 'unused',
            'disable' => 0,
            'deleted' => 0,
        ]);

        $workosUser = $this->createWorkosUser(
            id: 'user_06',
            email: 'existing@example.com',
            firstName: 'Existing',
            lastName: 'Person',
        );

        $result = $provisioning->resolve(LoginContext::Frontend, $workosUser);
        self::assertSame('preexisting', $result['username'], 'must link instead of creating a new record');
    }

    public function testResolveFrontendUserStoresTypedWorkosProfilePayload(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        $identity = $this->get(IdentityService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);
        self::assertInstanceOf(IdentityService::class, $identity);

        $workosUser = $this->createWorkosUser(
            id: 'user_07',
            email: 'profile@example.com',
            firstName: 'Profile',
            lastName: 'Carrier',
        );

        $provisioning->resolve(LoginContext::Frontend, $workosUser);

        $mapping = $identity->findIdentity(LoginContext::Frontend, 'user_07');
        self::assertIsArray($mapping);

        $profile = $identity->findProfileByLocalUser(
            LoginContext::Frontend,
            is_numeric($mapping['user_uid']) ? (int)$mapping['user_uid'] : 0
        );
        self::assertIsArray($profile);
        self::assertSame('user', $profile['object']);
        self::assertSame('user_07', $profile['id']);
        self::assertSame('profile@example.com', $profile['email']);
        self::assertSame('Profile', $profile['first_name']);
        self::assertSame('Carrier', $profile['last_name']);
        self::assertArrayHasKey('created_at', $profile);
        self::assertArrayHasKey('updated_at', $profile);
    }

    public function testResolveBackendUserRefusesToCreateWhenAutoCreateIsOff(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1744277605);
        $provisioning->resolve(LoginContext::Backend, $this->createWorkosUser('user_08', 'nobody@example.com', 'No', 'Body'));
    }

    public function testResolveBackendUserLinksExistingUserByEmailAndSynchronizesRealName(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        $identity = $this->get(IdentityService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);
        self::assertInstanceOf(IdentityService::class, $identity);

        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'pid' => 0,
            'username' => 'editor',
            'email' => 'editor@example.com',
            'password' => 'unused',
            'realName' => 'Old Name',
            'disable' => 0,
            'deleted' => 0,
        ]);

        $result = $provisioning->resolve(LoginContext::Backend, $this->createWorkosUser('user_09', 'Editor@Example.com', 'New', 'Name'));

        self::assertSame('editor', $result['username']);
        self::assertSame('New Name', $result['realName']);
        $mapping = $identity->findIdentity(LoginContext::Backend, 'user_09');
        self::assertIsArray($mapping);
        self::assertSame('be_users', $mapping['user_table']);
        self::assertSame('editor@example.com', $mapping['email']);
    }

    public function testResolveFrontendUserReturnsTheLinkedUserOnRepeatedSignIn(): void
    {
        $provisioning = $this->get(UserProvisioningService::class);
        self::assertInstanceOf(UserProvisioningService::class, $provisioning);
        $workosUser = $this->createWorkosUser('user_10', 'repeat@example.com', 'Re', 'Peat');

        $first = $provisioning->resolve(LoginContext::Frontend, $workosUser);
        $second = $provisioning->resolve(LoginContext::Frontend, $workosUser);

        self::assertSame($first['uid'], $second['uid']);
        self::assertSame(
            1,
            $this->getConnectionPool()->getConnectionForTable('fe_users')->count('uid', 'fe_users', ['email' => 'repeat@example.com'])
        );
    }

    private function createWorkosUser(string $id, string $email, string $firstName, string $lastName): User
    {
        return User::fromArray([
            'object' => 'user',
            'id' => $id,
            'email' => $email,
            'email_verified' => true,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'created_at' => '2026-04-20T00:00:00+00:00',
            'updated_at' => '2026-04-20T00:00:00+00:00',
        ]);
    }
}
