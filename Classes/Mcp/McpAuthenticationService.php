<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Mcp;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\MixedCaster;
use WebConsulting\WorkosAuth\Service\IdentityService;

final class McpAuthenticationService
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly RequestFactory $requestFactory,
        private readonly IdentityService $identityService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function authenticate(ServerRequestInterface $request): McpRequestContext
    {
        $workosRequired = $this->requiresWorkos();
        $bearerToken = $this->extractBearerToken($request);

        if ($bearerToken === null) {
            if ($workosRequired) {
                throw McpAuthenticationException::missingToken();
            }
            return new McpRequestContext(
                authenticationMode: WorkosConfiguration::MCP_AUTHENTICATION_ANONYMOUS,
                workosRequired: false,
            );
        }

        $authkitDomain = $this->configuration->getMcpAuthkitDomain();
        if ($authkitDomain === null) {
            if ($workosRequired) {
                throw McpAuthenticationException::missingAuthkitDomain();
            }
            return new McpRequestContext(
                authenticationMode: WorkosConfiguration::MCP_AUTHENTICATION_ANONYMOUS,
                workosRequired: false,
            );
        }

        $claims = $this->verifyToken($bearerToken, $authkitDomain);
        $workosUserId = $this->extractWorkosUserId($claims);
        if ($workosUserId === '') {
            throw McpAuthenticationException::invalidToken();
        }

        $frontend = $this->resolveLocalUser('frontend', 'fe_users', $workosUserId);
        $backend = $this->resolveLocalUser('backend', 'be_users', $workosUserId);

        return new McpRequestContext(
            authenticationMode: WorkosConfiguration::MCP_AUTHENTICATION_WORKOS,
            workosRequired: $workosRequired,
            workosUserId: $workosUserId,
            email: $this->extractEmail($claims),
            frontendUserUid: $frontend['uid'],
            frontendGroupUids: $frontend['groupUids'],
            backendUserUid: $backend['uid'],
            backendGroupUids: $backend['groupUids'],
            claims: $claims,
        );
    }

    public function requiresWorkos(): bool
    {
        return match ($this->configuration->getMcpAuthenticationMode()) {
            WorkosConfiguration::MCP_AUTHENTICATION_WORKOS => true,
            WorkosConfiguration::MCP_AUTHENTICATION_ANONYMOUS => false,
            default => $this->isProductionContext(),
        };
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
    }

    private function isProductionContext(): bool
    {
        try {
            return Environment::getContext()->isProduction();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyToken(string $token, string $authkitDomain): array
    {
        try {
            $jwks = $this->fetchJwks($authkitDomain);
            $keys = JWK::parseKeySet($jwks, 'RS256');
            $decoded = JWT::decode($token, $keys);
            $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw McpAuthenticationException::invalidToken();
        }

        if (!is_array($claims)) {
            throw McpAuthenticationException::invalidToken();
        }

        $issuer = MixedCaster::string($claims['iss'] ?? null);
        if (rtrim($issuer, '/') !== rtrim($authkitDomain, '/')) {
            throw McpAuthenticationException::invalidToken();
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $authkitDomain): array
    {
        $response = $this->requestFactory->request(
            rtrim($authkitDomain, '/') . '/oauth2/jwks',
            'GET',
            ['timeout' => 5],
            'workos-auth-mcp'
        );
        $decoded = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw McpAuthenticationException::invalidToken();
        }

        $jwks = [];
        foreach ($decoded as $key => $value) {
            $jwks[(string)$key] = $value;
        }
        return $jwks;
    }

    /**
     * @return array{uid: ?int, groupUids: list<int>}
     */
    private function resolveLocalUser(string $context, string $table, string $workosUserId): array
    {
        $identity = $this->identityService->findIdentity($context, $workosUserId);
        if ($identity === null) {
            return ['uid' => null, 'groupUids' => []];
        }

        $uid = MixedCaster::int($identity['user_uid'] ?? null);
        if ($uid <= 0) {
            return ['uid' => null, 'groupUids' => []];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'usergroup')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return ['uid' => null, 'groupUids' => []];
        }

        return [
            'uid' => MixedCaster::int($row['uid'] ?? null),
            'groupUids' => $this->parseGroupUids(MixedCaster::string($row['usergroup'] ?? null)),
        ];
    }

    /**
     * @return list<int>
     */
    private function parseGroupUids(string $value): array
    {
        $split = preg_split('/[,\s;]+/', $value);
        $items = $split === false ? [] : $split;
        $groupUids = array_map(static fn(string $item): int => (int)$item, $items);
        return array_values(array_filter($groupUids, static fn(int $uid): bool => $uid > 0));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function extractWorkosUserId(array $claims): string
    {
        return trim(MixedCaster::string(
            $claims['sub'] ?? $claims['user_id'] ?? $claims['userId'] ?? null
        ));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function extractEmail(array $claims): ?string
    {
        $email = trim(MixedCaster::string($claims['email'] ?? null));
        return $email !== '' ? $email : null;
    }
}
