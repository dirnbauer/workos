<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaRequiredException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;

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

    public function testCreateFrontendLoginResponseReusesRequestFrontendUser(): void
    {
        $this->connectionPool()->getConnectionForTable('fe_users')->insert(
            'fe_users',
            [
                'pid' => 1,
                'username' => 'request-frontend-workos',
                'password' => 'unused',
                'email' => 'request-frontend@example.com',
                'disable' => 0,
                'deleted' => 0,
            ]
        );

        $userRow = $this->fetchUserRow('fe_users', (int)$this->connectionPool()->getConnectionForTable('fe_users')->lastInsertId());
        $request = $this->createRequest('https://app.local/login');
        $frontendUser = new FrontendUserAuthentication();
        $frontendUser->setLogger(new NullLogger());
        $frontendUser->start($request);
        $request = $request->withAttribute('frontend.user', $frontendUser);

        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);

        $response = $service->createFrontendLoginResponse(
            $request,
            $userRow,
            '/welcome'
        );

        $expectedUid = $this->intFromMixed($userRow['uid'] ?? null);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame($expectedUid, $this->intFromMixed($frontendUser->user['uid'] ?? null));
        $this->assertFrontendSessionExistsForUser($expectedUid);
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
            'user_123',
            'session_456'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Continue to the TYPO3 backend', (string)$response->getBody());
        self::assertStringContainsString('/typo3/main', (string)$response->getBody());
        // The meta refresh navigates; an inline script would only trip the backend CSP.
        self::assertStringNotContainsString('<script', (string)$response->getBody());

        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        self::assertSame('user_123', $backendUser->getSessionData(Typo3SessionService::SESSION_WORKOS_USER_ID));
        self::assertSame('session_456', $backendUser->getSessionData(Typo3SessionService::SESSION_WORKOS_SESSION_ID));
    }

    public function testTheFrontendSessionRemembersTheWorkosSession(): void
    {
        $this->connectionPool()->getConnectionForTable('fe_users')->insert(
            'fe_users',
            [
                'pid' => 1,
                'username' => 'frontend-session',
                'password' => 'unused',
                'email' => 'frontend-session@example.com',
                'disable' => 0,
                'deleted' => 0,
            ]
        );
        $userRow = $this->fetchUserRow('fe_users', (int)$this->connectionPool()->getConnectionForTable('fe_users')->lastInsertId());
        $request = $this->createRequest('https://app.local/workos-auth/frontend/callback');
        $frontendUser = new FrontendUserAuthentication();
        $frontendUser->setLogger(new NullLogger());
        $frontendUser->start($request);

        $service = $this->get(Typo3SessionService::class);
        self::assertInstanceOf(Typo3SessionService::class, $service);
        $service->createFrontendLoginResponse($request->withAttribute('frontend.user', $frontendUser), $userRow, '/welcome', 'session_789');

        self::assertSame('session_789', $frontendUser->getSessionData(Typo3SessionService::SESSION_WORKOS_SESSION_ID));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function backendMfaPolicyProvider(): iterable
    {
        yield 'external policy, no authenticator enrolled' => [false, false];
        yield 'external policy, authenticator already enrolled' => [false, true];
        yield 'TYPO3 policy, no authenticator enrolled' => [true, false];
        yield 'TYPO3 policy, authenticator already enrolled' => [true, true];
    }

    #[DataProvider('backendMfaPolicyProvider')]
    public function testWorkosLoginAppliesOnlyItsConfiguredMfaPolicy(bool $requireMfa, bool $enrolled): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['requireMfa'] = 3;
        $configuration = $this->get(WorkosConfiguration::class);
        $configuration->save($configuration->normalizeInput(['backendRequireMfa' => $requireMfa]));
        $connection = $this->connectionPool()->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'username' => 'workos-admin',
            'password' => 'unused',
            'email' => 'admin@example.com',
            'admin' => 1,
            'mfa' => $enrolled ? json_encode(['totp' => ['active' => true, 'secret' => 'synthetic-test-secret']], JSON_THROW_ON_ERROR) : '',
        ]);
        $userRow = $this->fetchUserRow('be_users', (int)$connection->lastInsertId());
        if ($requireMfa && $enrolled) {
            $this->expectException(MfaRequiredException::class);
        }

        $response = $this->get(Typo3SessionService::class)->createBackendLoginResponse(
            $this->createRequest('https://app.local/typo3/workos-auth/backend/magic-verify'),
            $userRow,
            '/typo3/main',
            'user_verified',
            'session_verified',
        );

        self::assertSame(200, $response->getStatusCode());
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        self::assertSame(!$requireMfa, (bool)$backendUser->getSessionData('mfa'));
        self::assertSame(3, $GLOBALS['TYPO3_CONF_VARS']['BE']['requireMfa'], 'The global administrator policy stays enabled');
        // A fresh ordinary login still has to enroll MFA, even for the same user.
        $ordinaryUser = new BackendUserAuthentication();
        $ordinaryUser->user = $userRow;
        $ordinaryUser->initializeUserSessionManager();
        self::assertTrue($ordinaryUser->isMfaSetupRequired());
        self::assertFalse((bool)$ordinaryUser->getSessionData('mfa'));
    }

    public function testClassicPasswordLoginStillRequiresItsEnrolledAuthenticator(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['requireMfa'] = 3;
        $configuration = $this->get(WorkosConfiguration::class);
        $configuration->save($configuration->normalizeInput(['backendRequireMfa' => false]));
        $connection = $this->connectionPool()->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'username' => 'classic-admin',
            'password' => $this->get(PasswordHashFactory::class)->getDefaultHashInstance('BE')->getHashedPassword('synthetic-test-password'),
            'admin' => 1,
            'mfa' => json_encode(['totp' => ['active' => true, 'secret' => 'synthetic-test-secret']], JSON_THROW_ON_ERROR),
        ]);
        SecurityAspect::provideIn($this->get(Context::class))->setReceivedRequestToken(RequestToken::create('core/user-auth/be'));
        $request = $this->createRequest('https://app.local/typo3/login')->withMethod('POST')->withParsedBody([
            'login_status' => 'login',
            'username' => 'classic-admin',
            'userident' => 'synthetic-test-password',
        ]);
        $backendUser = new BackendUserAuthentication();
        $backendUser->setLogger(new NullLogger());
        $GLOBALS['BE_USER'] = $backendUser;

        $this->expectException(MfaRequiredException::class);
        $backendUser->start($request);
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

    private function assertFrontendSessionExistsForUser(int $userId): void
    {
        $row = $this->connectionPool()
            ->getConnectionForTable('fe_sessions')
            ->select(['ses_userid'], 'fe_sessions', ['ses_userid' => $userId])
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame($userId, $this->intFromMixed($row['ses_userid'] ?? null));
    }

    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        return $connectionPool;
    }
}
