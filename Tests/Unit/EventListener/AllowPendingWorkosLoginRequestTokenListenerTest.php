<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;
use WebConsulting\WorkosAuth\EventListener\AllowPendingWorkosLoginRequestTokenListener;

final class AllowPendingWorkosLoginRequestTokenListenerTest extends TestCase
{
    public function testListenerIssuesFrontendRequestTokenForPendingFrontendLogin(): void
    {
        $listener = new AllowPendingWorkosLoginRequestTokenListener();
        $request = (new ServerRequest(new Uri('https://app.local/workos-auth/frontend/callback')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'frontend',
                'user' => ['uid' => 123],
            ]);
        $event = new BeforeRequestTokenProcessedEvent(
            new FrontendUserAuthentication(),
            $request,
            null
        );

        $listener($event);

        $requestToken = $event->getRequestToken();
        self::assertInstanceOf(RequestToken::class, $requestToken);
        self::assertSame('core/user-auth/fe', $requestToken->scope);
    }

    public function testListenerDoesNotOverrideExistingOrMismatchedRequestTokenState(): void
    {
        $listener = new AllowPendingWorkosLoginRequestTokenListener();
        $request = (new ServerRequest(new Uri('https://app.local/workos-auth/frontend/callback')))
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => 'frontend',
                'user' => ['uid' => 123],
            ]);
        $existingToken = RequestToken::create('custom/scope');
        $event = new BeforeRequestTokenProcessedEvent(
            new BackendUserAuthentication(),
            $request,
            $existingToken
        );

        $listener($event);

        self::assertSame($existingToken, $event->getRequestToken());
    }
}
