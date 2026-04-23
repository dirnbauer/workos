<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Security\RequestToken;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;

final class Typo3SessionServiceTest extends TestCase
{
    private Context $context;
    private SecurityAspect $securityAspect;
    private Typo3SessionService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new Context();
        $this->securityAspect = SecurityAspect::provideIn($this->context);
        $this->subject = new Typo3SessionService(new NullLogger(), $this->context);
    }

    public function testRunWithCoreLoginRequestTokenOverridesAndRestoresPreviousScope(): void
    {
        $this->securityAspect->setReceivedRequestToken(
            RequestToken::create('workos/frontend/login')
        );

        $scopes = [];
        $this->invokeRunWithCoreLoginRequestToken(
            'core/user-auth/fe',
            function () use (&$scopes): void {
                $activeRequestToken = $this->securityAspect->getReceivedRequestToken();
                self::assertInstanceOf(RequestToken::class, $activeRequestToken);
                $scopes[] = $activeRequestToken->scope;
            }
        );

        self::assertSame(['core/user-auth/fe'], $scopes);
        $restoredRequestToken = $this->securityAspect->getReceivedRequestToken();
        self::assertInstanceOf(RequestToken::class, $restoredRequestToken);
        self::assertSame('workos/frontend/login', $restoredRequestToken->scope);
    }

    public function testRunWithCoreLoginRequestTokenRestoresNullTokenAfterException(): void
    {
        $this->securityAspect->setReceivedRequestToken(null);

        try {
            $this->invokeRunWithCoreLoginRequestToken(
                'core/user-auth/be',
                static function (): void {
                    throw new \RuntimeException('boom');
                }
            );
            self::fail('Expected runtime exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertNull($this->securityAspect->getReceivedRequestToken());
    }

    private function invokeRunWithCoreLoginRequestToken(string $scope, callable $callback): void
    {
        $runner = \Closure::bind(
            function (string $scope, callable $callback): void {
                $this->runWithCoreLoginRequestToken($scope, $callback);
            },
            $this->subject,
            Typo3SessionService::class
        );

        self::assertInstanceOf(\Closure::class, $runner);
        $runner($scope, $callback);
    }
}
