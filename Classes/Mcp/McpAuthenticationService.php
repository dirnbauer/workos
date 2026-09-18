<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\PathUtility;

/**
 * Verifies AuthKit bearer tokens against the configured AuthKit domain
 * (JWKS signature, issuer, exact resource audience, expiration) and maps the
 * WorkOS subject to linked TYPO3 users.
 */
final readonly class McpAuthenticationService
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private RequestFactory $requestFactory,
        private IdentityService $identityService,
        private ConnectionPool $connectionPool,
        private McpTokenClaimsValidator $tokenClaimsValidator,
    ) {}

    /**
     * @throws McpAuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): McpRequestContext
    {
        $workosRequired = $this->configuration->mcpRequiresWorkos();
        $bearerToken = $this->extractBearerToken($request);
        $authkitDomain = $this->configuration->getMcpAuthkitDomain();

        if ($bearerToken === null || $authkitDomain === null) {
            if ($workosRequired) {
                throw $bearerToken === null
                    ? McpAuthenticationException::missingToken()
                    : McpAuthenticationException::missingAuthkitDomain();
            }

            return McpRequestContext::anonymous();
        }

        $claims = $this->verifyToken(
            $bearerToken,
            $authkitDomain,
            PathUtility::buildAbsoluteUrlFromRequest($request, $this->configuration->getMcpServerPath()),
        );
        $workosUserId = trim(MixedCaster::string($claims['sub'] ?? null));
        if ($workosUserId === '') {
            throw McpAuthenticationException::invalidToken();
        }

        $frontend = $this->resolveLocalUser(LoginContext::Frontend, $workosUserId);
        $backend = $this->resolveLocalUser(LoginContext::Backend, $workosUserId);
        $email = trim(MixedCaster::string($claims['email'] ?? null));

        return new McpRequestContext(
            authenticationMode: McpAuthenticationMode::Workos,
            workosRequired: $workosRequired,
            workosUserId: $workosUserId,
            email: $email !== '' ? $email : null,
            frontendUserUid: $frontend['uid'],
            frontendGroupUids: $frontend['groupUids'],
            backendUserUid: $backend['uid'],
            backendGroupUids: $backend['groupUids'],
            claims: $claims,
        );
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($request->getHeaderLine('Authorization')), $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token !== '' ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyToken(string $token, string $authkitDomain, string $expectedAudience): array
    {
        try {
            $keys = JWK::parseKeySet($this->fetchJson(rtrim($authkitDomain, '/') . '/oauth2/jwks'), 'RS256');
            $decoded = JWT::decode($token, $keys);
            $claims = MixedCaster::stringKeyedArray(json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw McpAuthenticationException::invalidToken();
        }

        if ($claims === null || !$this->tokenClaimsValidator->isValid($claims, $authkitDomain, $expectedAudience)) {
            throw McpAuthenticationException::invalidToken();
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->requestFactory->request($url, 'GET', ['timeout' => 5], 'workos-auth-mcp');

        return MixedCaster::stringKeyedArray(json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR))
            ?? throw McpAuthenticationException::invalidToken();
    }

    /**
     * @return array{uid: ?int, groupUids: list<int>}
     */
    private function resolveLocalUser(LoginContext $context, string $workosUserId): array
    {
        $identity = $this->identityService->findIdentity($context, $workosUserId);
        $uid = $identity === null ? 0 : MixedCaster::int($identity['user_uid'] ?? null);
        if ($uid <= 0) {
            return ['uid' => null, 'groupUids' => []];
        }

        $table = $context->userTable();
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
            'groupUids' => GeneralUtility::intExplode(',', MixedCaster::string($row['usergroup'] ?? null), true),
        ];
    }
}
