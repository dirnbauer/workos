<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Exception\AccountNotLinkedException;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use WorkOS\Resource\User;

/**
 * Resolves the local fe_users / be_users record for an authenticated WorkOS
 * user: identity link first, then (optionally) an existing user with the same
 * email, then (optionally) a newly created user. The identity link and the
 * stored WorkOS profile are refreshed on every sign-in.
 *
 * Only active accounts count — not disabled, not deleted, inside their start
 * and end time, exactly like a password login. Matching by email and
 * creating backend accounts both require an email address WorkOS verified:
 * otherwise anyone able to register that address with the identity
 * provider would take over the TYPO3 account that uses it.
 */
final readonly class UserProvisioningService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private IdentityService $identityService,
        private PasswordHashFactory $passwordHashFactory,
        private WorkosConfiguration $configuration,
        private Context $context,
    ) {}

    /**
     * @return array<string, mixed> the enabled, non-deleted local user row
     */
    public function resolve(LoginContext $context, User $workosUser): array
    {
        $workosUserId = trim($workosUser->id);
        $email = strtolower(trim($workosUser->email));
        if ($workosUserId === '' || $email === '') {
            throw new \RuntimeException('The WorkOS user response is missing an id or email address.', 1744277601);
        }

        $user = $this->findLinkedUser($context, $workosUserId);
        if ($user === null && $this->shouldLinkByEmail($context)) {
            $user = $this->findUserByEmail($context->userTable(), $email);
            if ($user !== null && !$workosUser->emailVerified) {
                throw new \RuntimeException(sprintf(
                    'The WorkOS account "%s" has not verified its email address, so it is not linked to the existing %s user with that address.',
                    $workosUser->id,
                    $context->value
                ), 1744277609);
            }
        }

        $user = $user === null
            ? $this->createUser($context, $workosUser, $email)
            : $this->synchronizeProfile($context, $user, $workosUser, $email);

        $this->identityService->storeIdentity(
            $context,
            $workosUserId,
            $email,
            MixedCaster::int($user['uid']),
            $workosUser->toArray()
        );

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLinkedUser(LoginContext $context, string $workosUserId): ?array
    {
        $identity = $this->identityService->findIdentity($context, $workosUserId);
        if ($identity === null) {
            return null;
        }

        $user = $this->findUserByUid($context->userTable(), MixedCaster::int($identity['user_uid']));
        if ($user === null && $this->userRecordExists($context->userTable(), MixedCaster::int($identity['user_uid']))) {
            // Linked, but disabled or outside its start/end time: falling
            // through to matching by email or creating a new account would
            // resurrect access an administrator withdrew.
            throw new \RuntimeException(sprintf(
                'The %s user linked to the WorkOS account "%s" is disabled or expired.',
                $context->value,
                $workosUserId
            ), 1744277610);
        }

        return $user;
    }

    private function shouldLinkByEmail(LoginContext $context): bool
    {
        return match ($context) {
            LoginContext::Frontend => $this->configuration->shouldLinkFrontendUsersByEmail(),
            LoginContext::Backend => $this->configuration->shouldLinkBackendUsersByEmail(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function createUser(LoginContext $context, User $workosUser, string $email): array
    {
        $table = $context->userTable();
        $timestamp = $this->currentTimestamp();
        $row = $this->newUserRow($context, $workosUser) + $this->profileFields($context, $workosUser, $email) + [
            'tstamp' => $timestamp,
            'crdate' => $timestamp,
            'disable' => 0,
            'username' => $this->generateUniqueUsername($table, $context->loginTypeAbbreviation(), $workosUser->id),
            'password' => $this->hashRandomPassword(strtoupper($context->loginTypeAbbreviation())),
        ];

        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->insert($table, $row);

        return $this->findUserByUid($table, (int)$connection->lastInsertId())
            ?? throw new \RuntimeException(sprintf('The %s user could not be loaded after creation.', $context->value), 1744277604);
    }

    /**
     * Context-specific columns of a new user, after checking that automatic
     * provisioning is allowed for this context and WorkOS account.
     *
     * @return array<string, int|string>
     */
    private function newUserRow(LoginContext $context, User $workosUser): array
    {
        $autoCreate = match ($context) {
            LoginContext::Frontend => $this->configuration->shouldAutoCreateFrontendUsers(),
            LoginContext::Backend => $this->configuration->shouldAutoCreateBackendUsers(),
        };
        if (!$autoCreate) {
            throw new AccountNotLinkedException(
                $context,
                $workosUser->email,
                $workosUser->id,
                sprintf(
                    'No %s user matched the WorkOS account (email "%s", id "%s") and automatic %1$s provisioning is disabled.',
                    $context->value,
                    $workosUser->email,
                    $workosUser->id
                ),
                $context === LoginContext::Frontend ? 1744277602 : 1744277605,
            );
        }

        if ($context === LoginContext::Frontend) {
            $storagePid = $this->configuration->getFrontendStoragePid();
            if ($storagePid <= 0) {
                throw new \RuntimeException('Automatic frontend provisioning requires a storage PID.', 1744277603);
            }

            return [
                'pid' => $storagePid,
                'usergroup' => implode(',', $this->configuration->getFrontendDefaultGroupUids()),
            ];
        }

        if (!$workosUser->emailVerified) {
            throw new \RuntimeException('Only a WorkOS account with a verified email address can create a TYPO3 backend user.', 1744277611);
        }
        $this->assertBackendDomainAllowed($workosUser->email);

        return [
            'pid' => 0,
            'admin' => 0,
            'usergroup' => implode(',', $this->configuration->getBackendDefaultGroupUids()),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function synchronizeProfile(LoginContext $context, array $user, User $workosUser, string $email): array
    {
        $table = $context->userTable();
        $uid = MixedCaster::int($user['uid']);
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            $this->profileFields($context, $workosUser, $email) + ['tstamp' => $this->currentTimestamp()],
            ['uid' => $uid]
        );

        return $this->findUserByUid($table, $uid) ?? $user;
    }

    /**
     * Columns mirrored from the WorkOS profile on every sign-in.
     *
     * @return array<string, string>
     */
    private function profileFields(LoginContext $context, User $workosUser, string $email): array
    {
        $displayName = self::buildDisplayName($workosUser);

        return match ($context) {
            LoginContext::Frontend => [
                'email' => $email,
                'name' => $displayName,
                'first_name' => trim($workosUser->firstName ?? ''),
                'last_name' => trim($workosUser->lastName ?? ''),
            ],
            LoginContext::Backend => [
                'email' => $email,
                'realName' => $displayName,
            ],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByUid(string $table, int $uid): ?array
    {
        $queryBuilder = $this->createActiveUserQuery($table);
        $user = $queryBuilder
            ->andWhere($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($user) ? $user : null;
    }

    /**
     * The one active user with this email address, or null.
     *
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $table, string $email): ?array
    {
        $queryBuilder = $this->createActiveUserQuery($table);
        $users = $queryBuilder
            ->andWhere($queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($email)))
            ->setMaxResults(2)
            ->executeQuery()
            ->fetchAllAssociative();

        if (count($users) > 1) {
            // Picking one would let the database's row order decide which
            // account — possibly an administrator's — the sign-in opens.
            throw new \RuntimeException(sprintf(
                'More than one %s account uses the email address of this WorkOS account; link one of them explicitly.',
                $table
            ), 1744277612);
        }

        return $users[0] ?? null;
    }

    /**
     * A query for users a password login would accept: not deleted, not
     * disabled, and inside their start and end time.
     */
    private function createActiveUserQuery(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction())
            ->add(new StartTimeRestriction())
            ->add(new EndTimeRestriction());

        return $queryBuilder->select('*')->from($table);
    }

    private function userRecordExists(string $table, int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $count = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return MixedCaster::int($count) > 0;
    }

    private function generateUniqueUsername(string $table, string $prefix, string $seed): string
    {
        $baseUsername = sprintf('workos_%s_%s', $prefix, substr(sha1($seed), 0, 12));
        $candidate = $baseUsername;
        $counter = 1;

        while ($this->usernameExists($table, $candidate)) {
            $candidate = $baseUsername . '_' . $counter++;
        }

        return $candidate;
    }

    private function usernameExists(string $table, string $username): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)))
            ->executeQuery()
            ->fetchOne();

        return MixedCaster::int($count) > 0;
    }

    private function hashRandomPassword(string $mode): string
    {
        $hash = $this->passwordHashFactory->getDefaultHashInstance($mode)->getHashedPassword(bin2hex(random_bytes(32)));
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('A TYPO3 password hash could not be generated.', 1744277607);
        }

        return $hash;
    }

    private function currentTimestamp(): int
    {
        return MixedCaster::int($this->context->getPropertyFromAspect('date', 'timestamp'), time());
    }

    private static function buildDisplayName(User $workosUser): string
    {
        $displayName = trim(trim($workosUser->firstName ?? '') . ' ' . trim($workosUser->lastName ?? ''));

        return $displayName !== '' ? $displayName : $workosUser->email;
    }

    private function assertBackendDomainAllowed(string $email): void
    {
        $allowedDomains = $this->configuration->getBackendAllowedDomains();
        if ($allowedDomains === []) {
            return;
        }

        $atPosition = strrpos($email, '@');
        $domain = $atPosition === false ? '' : strtolower(substr($email, $atPosition + 1));
        if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
            throw new \RuntimeException('This WorkOS account is not allowed to create a TYPO3 backend user.', 1744277608);
        }
    }
}
