<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Functional\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;

final class Typo3SessionServiceTest extends FunctionalTestCase
{
    /**
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'webconsulting/workos-auth',
    ];

    public function testCreateFrontendLoginResponseUsesTypo3AuthService(): void
    {
        $this->connectionPool()->getConnectionForTable('fe_users')->insert(
            'fe_users',
            [
                'pid' => 1,
                'username' => 'frontend-workos',
                'password' => 'unused',
                'email' => 'frontend@example.com',
                'disable' => 0,
                'deleted' => 0,
            ]
        );

        $userRow = $this->fetchUserRow('fe_users', (int)$this->connectionPool()->getConnectionForTable('fe_users')->lastInsertId());
        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);

        $response = $service->createFrontendLoginResponse(
            $this->createRequest('https://app.local/workos-auth/frontend/callback'),
            $userRow,
            '/welcome'
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/welcome', $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testCreateFrontendLoginResponseOverridesNonCoreRequestTokenScope(): void
    {
        $this->connectionPool()->getConnectionForTable('fe_users')->insert(
            'fe_users',
            [
                'pid' => 1,
                'username' => 'frontend-workos-token',
                'password' => 'unused',
                'email' => 'frontend-token@example.com',
                'disable' => 0,
                'deleted' => 0,
            ]
        );

        $userRow = $this->fetchUserRow('fe_users', (int)$this->connectionPool()->getConnectionForTable('fe_users')->lastInsertId());
        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);

        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        SecurityAspect::provideIn($context)->setReceivedRequestToken(
            RequestToken::create('workos/frontend/login')
        );

        $response = $service->createFrontendLoginResponse(
            $this->createRequest('https://app.local/workos-auth/frontend/callback'),
            $userRow,
            '/welcome'
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/welcome', $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(
            'workos/frontend/login',
            SecurityAspect::provideIn($context)->getReceivedRequestToken()?->scope
        );
    }

    public function testCreateBackendLoginResponseUsesTypo3AuthService(): void
    {
        $this->connectionPool()->getConnectionForTable('be_users')->insert(
            'be_users',
            [
                'pid' => 0,
                'username' => 'backend-workos',
                'password' => 'unused',
                'email' => 'backend@example.com',
                'realName' => 'Backend WorkOS',
                'admin' => 1,
                'disable' => 0,
                'deleted' => 0,
            ]
        );

        $userRow = $this->fetchUserRow('be_users', (int)$this->connectionPool()->getConnectionForTable('be_users')->lastInsertId());
        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);

        $response = $service->createBackendLoginResponse(
            $this->createRequest('https://app.local/typo3/workos-auth/backend/callback'),
            $userRow,
            '/typo3/main',
            'user_123'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Continue to the TYPO3 backend', (string)$response->getBody());
    }

    public function testCreateBackendLoginResponseOverridesNonCoreRequestTokenScope(): void
    {
        $this->connectionPool()->getConnectionForTable('be_users')->insert(
            'be_users',
            [
                'pid' => 0,
                'username' => 'backend-workos-token',
                'password' => 'unused',
                'email' => 'backend-token@example.com',
                'realName' => 'Backend WorkOS Token',
                'admin' => 1,
                'disable' => 0,
                'deleted' => 0,
            ]
        );

        $userRow = $this->fetchUserRow('be_users', (int)$this->connectionPool()->getConnectionForTable('be_users')->lastInsertId());
        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);

        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        SecurityAspect::provideIn($context)->setReceivedRequestToken(
            RequestToken::create('workos/frontend/login')
        );

        $response = $service->createBackendLoginResponse(
            $this->createRequest('https://app.local/typo3/workos-auth/backend/callback'),
            $userRow,
            '/typo3/main',
            'user_123'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Continue to the TYPO3 backend', (string)$response->getBody());
        self::assertSame(
            'workos/frontend/login',
            SecurityAspect::provideIn($context)->getReceivedRequestToken()?->scope
        );
    }

    private function createRequest(string $uri): ServerRequestInterface
    {
        $parsedUri = new Uri($uri);
        $serverParams = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => $parsedUri->getHost(),
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $parsedUri->getPath(),
            'SCRIPT_NAME' => '/index.php',
            'SERVER_PORT' => (string)($parsedUri->getPort() ?? 443),
            'HTTPS' => $parsedUri->getScheme() === 'https' ? 'on' : 'off',
        ];

        $_SERVER['REMOTE_ADDR'] = $serverParams['REMOTE_ADDR'];

        return (new ServerRequest($parsedUri, 'GET', 'php://input', [], $serverParams))
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserRow(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid))
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        $narrowed = [];
        foreach ($row as $key => $value) {
            $narrowed[(string)$key] = $value;
        }

        return $narrowed;
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        return $connectionPool;
    }
}
