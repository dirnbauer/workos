<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * CSRF tokens for frontend plugin state-changing actions.
 *
 * Tokens are HMAC'd so an attacker cannot forge them even if they
 * observe previous values. The secret is the fe_typo_user session id,
 * which is not exposed to JavaScript and is scoped to the logged-in
 * user. Tokens are single-scope (e.g. "account", "team") so a token
 * issued for the Account plugin cannot be replayed against the Team
 * plugin.
 */
final class FrontendCsrfService
{
    public function __construct(
        private readonly HashService $hashService,
    ) {}

    public function issue(FrontendUserAuthentication $user, string $scope): string
    {
        return $this->hashService->hmac($this->secret($user) . ':' . $scope, self::class);
    }

    public function validate(FrontendUserAuthentication $user, string $scope, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return hash_equals($this->issue($user, $scope), $token);
    }

    private function secret(FrontendUserAuthentication $user): string
    {
        $sessionId = $user->getSession()->getIdentifier();
        if ($sessionId === '') {
            throw new \RuntimeException('Cannot issue CSRF token: no frontend session is active.', 1744278200);
        }
        return $sessionId;
    }
}
