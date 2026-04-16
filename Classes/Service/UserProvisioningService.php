<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Resource\User;

final class UserProvisioningService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private IdentityService $identityService,
        private PasswordHashFactory $passwordHashFactory,
        private WorkosConfiguration $configuration,
    ) {}

    public function resolveFrontendUser(User $workosUser): array
    {
        return $this->resolveUser($workosUser, 'frontend', 'fe_users');
    }

    public function resolveBackendUser(User $workosUser): array
    {
        return $this->resolveUser($workosUser, 'backend', 'be_users');
    }

    private function resolveUser(User $workosUser, string $context, string $table): array
    {
        $workosUserId = trim((string)$workosUser->id);
        $email = strtolower(trim((string)$workosUser->email));
        if ($workosUserId === '' || $email === '') {
            throw new \RuntimeException('The WorkOS user response is missing an id or email address.', 1744277601);
        }

        $identity = $this->identityService->findIdentity($context, $workosUserId);
        if ($identity !== null) {
            $linkedUser = $this->findUserByUid($table, (int)$identity['user_uid']);
            if ($linkedUser !== null) {
                $updatedUser = $context === 'frontend'
                    ? $this->synchronizeFrontendProfile($linkedUser, $workosUser)
                    : $this->synchronizeBackendProfile($linkedUser, $workosUser);
                $this->identityService->storeIdentity($context, $workosUserId, $email, $table, (int)$updatedUser['uid']);
                return $updatedUser;
            }
        }

        $linkByEmail = $context === 'frontend'
            ? $this->configuration->shouldLinkFrontendUsersByEmail()
            : $this->configuration->shouldLinkBackendUsersByEmail();

        if ($linkByEmail) {
            $user = $this->findUserByEmail($table, $email);
            if ($user !== null) {
                $updatedUser = $context === 'frontend'
                    ? $this->synchronizeFrontendProfile($user, $workosUser)
                    : $this->synchronizeBackendProfile($user, $workosUser);
                $this->identityService->storeIdentity($context, $workosUserId, $email, $table, (int)$updatedUser['uid']);
                return $updatedUser;
            }
        }

        $user = $context === 'frontend'
            ? $this->createFrontendUser($workosUser)
            : $this->createBackendUser($workosUser);

        $this->identityService->storeIdentity($context, $workosUserId, $email, $table, (int)$user['uid']);
        return $user;
    }

    private function createFrontendUser(User $workosUser): array
    {
        if (!$this->configuration->shouldAutoCreateFrontendUsers()) {
            throw new \RuntimeException('No frontend user matched the WorkOS account and automatic frontend provisioning is disabled.', 1744277602);
        }

        $storagePid = $this->configuration->getFrontendStoragePid();
        if ($storagePid <= 0) {
            throw new \RuntimeException('Automatic frontend provisioning requires a storage PID.', 1744277603);
        }

        $email = strtolower(trim((string)$workosUser->email));
        $connection = $this->connectionPool->getConnectionForTable('fe_users');
        $connection->insert('fe_users', [
            'pid' => $storagePid,
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
            'crdate' => $GLOBALS['EXEC_TIME'] ?? time(),
            'disable' => 0,
            'username' => $this->generateUniqueUsername('fe_users', 'fe', (string)$workosUser->id),
            'password' => $this->hashRandomPassword('FE'),
            'email' => $email,
            'name' => $this->buildDisplayName($workosUser),
            'first_name' => trim((string)($workosUser->firstName ?? '')),
            'last_name' => trim((string)($workosUser->lastName ?? '')),
            'usergroup' => $this->configuration->getFrontendDefaultGroupCsv(),
        ]);

        return $this->findUserByUid('fe_users', (int)$connection->lastInsertId('fe_users'))
            ?? throw new \RuntimeException('The frontend user could not be loaded after creation.', 1744277604);
    }

    private function createBackendUser(User $workosUser): array
    {
        if (!$this->configuration->shouldAutoCreateBackendUsers()) {
            throw new \RuntimeException('No backend user matched the WorkOS account and automatic backend provisioning is disabled.', 1744277605);
        }

        $this->assertBackendDomainAllowed((string)$workosUser->email);

        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
            'crdate' => $GLOBALS['EXEC_TIME'] ?? time(),
            'disable' => 0,
            'admin' => 0,
            'username' => $this->generateUniqueUsername('be_users', 'be', (string)$workosUser->id),
            'password' => $this->hashRandomPassword('BE'),
            'email' => strtolower(trim((string)$workosUser->email)),
            'realName' => $this->buildDisplayName($workosUser),
            'usergroup' => $this->configuration->getBackendDefaultGroupCsv(),
        ]);

        return $this->findUserByUid('be_users', (int)$connection->lastInsertId('be_users'))
            ?? throw new \RuntimeException('The backend user could not be loaded after creation.', 1744277606);
    }

    private function synchronizeFrontendProfile(array $user, User $workosUser): array
    {
        $connection = $this->connectionPool->getConnectionForTable('fe_users');
        $connection->update('fe_users', [
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
            'email' => strtolower(trim((string)$workosUser->email)),
            'name' => $this->buildDisplayName($workosUser),
            'first_name' => trim((string)($workosUser->firstName ?? '')),
            'last_name' => trim((string)($workosUser->lastName ?? '')),
        ], [
            'uid' => (int)$user['uid'],
        ]);

        return $this->findUserByUid('fe_users', (int)$user['uid']) ?? $user;
    }

    private function synchronizeBackendProfile(array $user, User $workosUser): array
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $connection->update('be_users', [
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
            'email' => strtolower(trim((string)$workosUser->email)),
            'realName' => $this->buildDisplayName($workosUser),
        ], [
            'uid' => (int)$user['uid'],
        ]);

        return $this->findUserByUid('be_users', (int)$user['uid']) ?? $user;
    }

    private function findUserByUid(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $user = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($user) ? $user : null;
    }

    private function findUserByEmail(string $table, string $email): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $user = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($email)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($user) ? $user : null;
    }

    private function generateUniqueUsername(string $table, string $prefix, string $seed): string
    {
        $baseUsername = sprintf('workos_%s_%s', $prefix, substr(sha1($seed), 0, 12));
        $candidate = $baseUsername;
        $counter = 1;

        while ($this->usernameExists($table, $candidate)) {
            $candidate = $baseUsername . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function usernameExists(string $table, string $username): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $count = (int)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username))
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    private function hashRandomPassword(string $mode): string
    {
        $hashInstance = $this->passwordHashFactory->getDefaultHashInstance($mode);
        $hash = $hashInstance->getHashedPassword(bin2hex(random_bytes(32)));
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('A TYPO3 password hash could not be generated.', 1744277607);
        }

        return $hash;
    }

    private function buildDisplayName(User $workosUser): string
    {
        $displayName = trim(trim((string)($workosUser->firstName ?? '')) . ' ' . trim((string)($workosUser->lastName ?? '')));
        if ($displayName !== '') {
            return $displayName;
        }

        return (string)$workosUser->email;
    }

    private function assertBackendDomainAllowed(string $email): void
    {
        $allowedDomains = $this->configuration->getBackendAllowedDomains();
        if ($allowedDomains === []) {
            return;
        }

        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
            throw new \RuntimeException('This WorkOS account is not allowed to create a TYPO3 backend user.', 1744277608);
        }
    }
}
