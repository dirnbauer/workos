<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Security\RequestToken;
use WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;

#[AsEventListener('workos-auth/allow-pending-login-request-token')]
final class AllowPendingWorkosLoginRequestTokenListener
{
    public function __invoke(BeforeRequestTokenProcessedEvent $event): void
    {
        $pendingLogin = $event->getRequest()->getAttribute(
            WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE
        );
        if (!is_array($pendingLogin)) {
            return;
        }

        $context = $pendingLogin['context'] ?? null;
        if (!is_string($context) || !in_array($context, ['frontend', 'backend'], true)) {
            return;
        }

        $loginType = strtolower($event->getUser()->loginType);
        if (($loginType === 'be' && $context !== 'backend') || ($loginType === 'fe' && $context !== 'frontend')) {
            return;
        }

        $requestToken = $event->getRequestToken();
        $expectedScope = 'core/user-auth/' . $loginType;
        if ($requestToken instanceof RequestToken && $requestToken->scope === $expectedScope) {
            return;
        }
        if ($requestToken === false) {
            return;
        }

        $event->setRequestToken(
            RequestToken::create($expectedScope)
        );
    }
}
