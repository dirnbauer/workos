<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * Read/write access to `tx_workosauth_identity`, the link table between
 * WorkOS users and local fe_users / be_users records.
 */
final readonly class IdentityService
{
    private const string TABLE = 'tx_workosauth_identity';

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findIdentity(LoginContext $context, string $workosUserId): ?array
    {
        $queryBuilder = $this->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('login_context', $queryBuilder->createNamedParameter($context->value)),
                $queryBuilder->expr()->eq('workos_user_id', $queryBuilder->createNamedParameter($workosUserId))
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findIdentityByLocalUser(LoginContext $context, int $userUid): ?array
    {
        $queryBuilder = $this->createQueryBuilder();
        $row = $this->whereLocalUser($queryBuilder->select('*')->from(self::TABLE), $context, $userUid)
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The WorkOS profile stored at the last sign-in of a local user, decoded.
     *
     * @return array<string, mixed>|null
     */
    public function findProfileByLocalUser(LoginContext $context, int $userUid): ?array
    {
        $json = $this->findIdentityByLocalUser($context, $userUid)['workos_profile_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return null;
        }

        try {
            return MixedCaster::stringKeyedArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Insert or update the identity link. A local user keeps exactly one
     * mapping per context: older links to the same user are removed.
     *
     * @param array<string, mixed> $workosProfile
     */
    public function storeIdentity(
        LoginContext $context,
        string $workosUserId,
        string $email,
        int $userUid,
        array $workosProfile = [],
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $timestamp = $this->currentTimestamp();
        $data = [
            'tstamp' => $timestamp,
            'email' => $email,
            'user_table' => $context->userTable(),
            'user_uid' => $userUid,
            'workos_user_id' => $workosUserId,
            'workos_profile_json' => json_encode($workosProfile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];

        $existing = $this->findIdentity($context, $workosUserId) ?? $this->findIdentityByLocalUser($context, $userUid);
        if ($existing === null) {
            $connection->insert(self::TABLE, $data + [
                'pid' => 0,
                'crdate' => $timestamp,
                'login_context' => $context->value,
            ]);
            return;
        }

        $keepUid = MixedCaster::int($existing['uid']);
        $connection->update(self::TABLE, $data, ['uid' => $keepUid]);

        $queryBuilder = $this->createQueryBuilder();
        $this->whereLocalUser($queryBuilder->delete(self::TABLE), $context, $userUid)
            ->andWhere($queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($keepUid, Connection::PARAM_INT)))
            ->executeStatement();
    }

    private function createQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    private function whereLocalUser(QueryBuilder $queryBuilder, LoginContext $context, int $userUid): QueryBuilder
    {
        return $queryBuilder->where(
            $queryBuilder->expr()->eq('login_context', $queryBuilder->createNamedParameter($context->value)),
            $queryBuilder->expr()->eq('user_table', $queryBuilder->createNamedParameter($context->userTable())),
            $queryBuilder->expr()->eq('user_uid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT))
        );
    }

    private function currentTimestamp(): int
    {
        return MixedCaster::int($this->context->getPropertyFromAspect('date', 'timestamp'), time());
    }
}
