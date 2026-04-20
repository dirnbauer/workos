<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebConsulting\WorkosAuth\Security\RequestTokenService;

final class RequestTokenServiceTest extends TestCase
{
    private array $singletonInstances = [];
    private RequestTokenService $subject;
    private Context $context;
    private SecurityAspect $securityAspect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->singletonInstances = GeneralUtility::getSingletonInstances();
        GeneralUtility::purgeInstances();

        $this->context = new Context();
        GeneralUtility::setSingletonInstance(Context::class, $this->context);

        $this->securityAspect = SecurityAspect::provideIn($this->context);
        $this->subject = new RequestTokenService();
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        GeneralUtility::resetSingletonInstances($this->singletonInstances);
        parent::tearDown();
    }

    public function testCreateReturnsRequestTokenWithExpectedScope(): void
    {
        $token = $this->subject->create('workos/frontend/account');

        self::assertInstanceOf(RequestToken::class, $token);
        self::assertSame('workos/frontend/account', $token->scope);
    }

    public function testCreateHashedProducesJwtThatCanBeDecoded(): void
    {
        $jwt = $this->subject->createHashed('workos/frontend/account');
        $segments = explode('.', $jwt);

        self::assertCount(3, $segments);
        self::assertNotSame('', $segments[0]);
        self::assertNotSame('', $segments[1]);
        self::assertNotSame('', $segments[2]);
    }

    public function testValidateReturnsTrueForMatchingReceivedToken(): void
    {
        $this->securityAspect->setReceivedRequestToken(
            RequestToken::create('workos/frontend/account')
        );

        self::assertTrue($this->subject->validate('workos/frontend/account'));
    }

    public function testValidateReturnsFalseWhenNoTokenWasReceived(): void
    {
        $this->securityAspect->setReceivedRequestToken(null);

        self::assertFalse($this->subject->validate('workos/frontend/account'));
    }

    public function testValidateReturnsFalseForDifferentScope(): void
    {
        $this->securityAspect->setReceivedRequestToken(
            RequestToken::create('workos/frontend/team')
        );

        self::assertFalse($this->subject->validate('workos/frontend/account'));
    }

    public function testValidateReturnsFalseForInvalidTokenMarker(): void
    {
        $this->securityAspect->setReceivedRequestToken(false);

        self::assertFalse($this->subject->validate('workos/frontend/account'));
    }
}
