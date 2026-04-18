<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Functional\Service;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WorkOS\Resource\User;

/**
 * End-to-end coverage for fe_users / be_users provisioning. Fakes the
 * WorkOS user via the SDK's `constructFromResponse()` factory so we can
 * drive the service against a real database.
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
                'cookiePassword' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
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

        $workosUser = User::constructFromResponse([
            'id' => 'user_05',
            'email' => 'new@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
        ]);

        $result = $provisioning->resolveFrontendUser($workosUser);
        self::assertSame('new@example.com', $result['email']);
        self::assertSame('New User', $result['name']);

        $mapping = $identity->findIdentity('frontend', 'user_05');
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

        $workosUser = User::constructFromResponse([
            'id' => 'user_06',
            'email' => 'existing@example.com',
            'first_name' => 'Existing',
            'last_name' => 'Person',
        ]);

        $result = $provisioning->resolveFrontendUser($workosUser);
        self::assertSame('preexisting', $result['username'], 'must link instead of creating a new record');
    }
}
