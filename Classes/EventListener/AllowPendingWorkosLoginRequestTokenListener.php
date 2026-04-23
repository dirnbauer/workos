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
        if ($event->getRequestToken() !== null) {
            return;
        }

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

        $event->setRequestToken(
            RequestToken::create('core/user-auth/' . $loginType)
        );
    }
}
