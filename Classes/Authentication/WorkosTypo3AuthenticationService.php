<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Authentication\LoginType;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * TYPO3 auth service that completes a WorkOS login: it only acts when the
 * request carries the server-created pending-login attribute (set by
 * Typo3SessionService) and then hands TYPO3 the already-resolved user row.
 */
#[Autoconfigure(public: true)]
final class WorkosTypo3AuthenticationService extends AbstractAuthenticationService
{
    public const string PENDING_LOGIN_ATTRIBUTE = 'workos_auth.pending_login';

    private const string PLACEHOLDER_CREDENTIAL = '__workos__';

    /**
     * @param array<string, mixed> $loginData
     */
    public function processLoginData(array &$loginData): bool|int
    {
        if ($this->getPendingUser() === null) {
            return false;
        }

        $loginData['uname'] = self::PLACEHOLDER_CREDENTIAL;
        $loginData['uident'] = self::PLACEHOLDER_CREDENTIAL;
        $loginData['uident_text'] = self::PLACEHOLDER_CREDENTIAL;

        return 200;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getUser(): array|false
    {
        if (!$this->isActiveLogin()) {
            return false;
        }

        return $this->getPendingUser() ?? false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function authUser(array $user): int
    {
        if (!$this->isActiveLogin()) {
            return 100;
        }

        $pendingUser = $this->getPendingUser();
        if ($pendingUser === null) {
            return 100;
        }

        $pendingUid = MixedCaster::int($pendingUser['uid'] ?? null);

        return $pendingUid > 0 && MixedCaster::int($user['uid'] ?? null) === $pendingUid ? 200 : 0;
    }

    private function isActiveLogin(): bool
    {
        return LoginType::tryFrom(MixedCaster::string($this->login['status'] ?? null)) === LoginType::LOGIN;
    }

    /**
     * The pending user row when the request carries a pending login for the
     * context this service instance runs in (FE or BE), null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function getPendingUser(): ?array
    {
        $request = $this->authInfo['request'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $pendingLogin = MixedCaster::stringKeyedArray($request->getAttribute(self::PENDING_LOGIN_ATTRIBUTE));
        if ($pendingLogin === null
            || MixedCaster::string($pendingLogin['context'] ?? null) !== LoginContext::fromLoginType($this->mode)->value
        ) {
            return null;
        }

        return MixedCaster::stringKeyedArray($pendingLogin['user'] ?? null);
    }
}
