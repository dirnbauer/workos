<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Security\RequestToken;
use Webconsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;
use Webconsulting\WorkosAuth\Domain\LoginContext;

/**
 * TYPO3 core verifies a `core/user-auth/fe|be` request token during active
 * login processing. WorkOS forms validate their own scoped tokens before
 * calling WorkOS; this listener swaps to the core scope for the internal
 * handoff, but only for a server-created pending login and never for a
 * request token that RequestTokenMiddleware already resolved as invalid.
 */
#[AsEventListener('workos-auth/allow-pending-login-request-token')]
final class AllowPendingWorkosLoginRequestTokenListener
{
    public function __invoke(BeforeRequestTokenProcessedEvent $event): void
    {
        $pendingLogin = $event->getRequest()->getAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE);
        if (!is_array($pendingLogin)) {
            return;
        }

        $pendingContext = is_string($pendingLogin['context'] ?? null) ? LoginContext::tryFrom($pendingLogin['context']) : null;
        if ($pendingContext === null || $pendingContext !== LoginContext::fromLoginType($event->getUser()->loginType)) {
            return;
        }

        $requestToken = $event->getRequestToken();
        $expectedScope = $pendingContext->coreRequestTokenScope();
        if ($requestToken === false || ($requestToken instanceof RequestToken && $requestToken->scope === $expectedScope)) {
            return;
        }

        $event->setRequestToken(RequestToken::create($expectedScope));
    }
}
