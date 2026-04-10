<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class IdentityService
{
    private const TABLE = 'tx_workosauth_identity';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findIdentity(string $context, string $workosUserId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $identity = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('login_context', $queryBuilder->createNamedParameter($context)),
                $queryBuilder->expr()->eq('workos_user_id', $queryBuilder->createNamedParameter($workosUserId))
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($identity) ? $identity : null;
    }

    public function storeIdentity(
        string $context,
        string $workosUserId,
        string $email,
        string $userTable,
        int $userUid,
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existingIdentity = $this->findIdentity($context, $workosUserId);
        $timestamp = $GLOBALS['EXEC_TIME'] ?? time();

        $data = [
            'tstamp' => $timestamp,
            'email' => $email,
            'user_table' => $userTable,
            'user_uid' => $userUid,
        ];

        if ($existingIdentity === null) {
            $data['pid'] = 0;
            $data['crdate'] = $timestamp;
            $data['login_context'] = $context;
            $data['workos_user_id'] = $workosUserId;
            $connection->insert(self::TABLE, $data);
            return;
        }

        $connection->update(
            self::TABLE,
            $data,
            ['uid' => (int)$existingIdentity['uid']]
        );
    }
}
