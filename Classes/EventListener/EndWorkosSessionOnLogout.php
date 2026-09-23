<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\EventListener;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\BeforeUserLogoutEvent;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

/**
 * Logging out of TYPO3 also ends the WorkOS session the sign-in opened.
 *
 * Without this the hosted login still remembers the user, and on a shared
 * computer the next "Continue with WorkOS" signs the previous person in
 * again without asking for anything. Covers frontend and backend logouts;
 * a failure is logged and never blocks the TYPO3 logout.
 */
#[AsEventListener('workos-auth/end-workos-session-on-logout')]
final readonly class EndWorkosSessionOnLogout
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosAuthenticationService $workosAuthenticationService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(BeforeUserLogoutEvent $event): void
    {
        $sessionId = $event->getUserSession()?->get(Typo3SessionService::SESSION_WORKOS_SESSION_ID);
        if (!is_string($sessionId) || $sessionId === '' || !$this->configuration->hasWorkosCredentials()) {
            return;
        }

        try {
            $this->workosAuthenticationService->revokeSession($sessionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('The WorkOS session could not be ended on logout: ' . SecretRedactor::redact($exception->getMessage()));
        }
    }
}
