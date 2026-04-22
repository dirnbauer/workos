<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Authentication\LoginType;

final class WorkosTypo3AuthenticationService extends AbstractAuthenticationService
{
    public const PENDING_LOGIN_ATTRIBUTE = 'workos_auth.pending_login';

    private const PLACEHOLDER_USERNAME = '__workos__';
    private const PLACEHOLDER_PASSWORD = '__workos__';

    /**
     * @param array<string, mixed> $loginData
     */
    public function processLoginData(array &$loginData): bool|int
    {
        if (!$this->hasPendingLoginForCurrentMode()) {
            return false;
        }

        $loginData['uname'] = self::PLACEHOLDER_USERNAME;
        $loginData['uident'] = self::PLACEHOLDER_PASSWORD;
        $loginData['uident_text'] = self::PLACEHOLDER_PASSWORD;

        return 200;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getUser()
    {
        if (!$this->isActiveLogin() || !$this->hasPendingLoginForCurrentMode()) {
            return false;
        }

        $pendingUser = $this->getPendingUser();
        if (!is_array($pendingUser)) {
            return false;
        }

        return $pendingUser;
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
        if (!is_array($pendingUser) || !$this->hasPendingLoginForCurrentMode()) {
            return 100;
        }

        $pendingUid = self::intFromMixed($pendingUser['uid'] ?? null);
        $candidateUid = self::intFromMixed($user['uid'] ?? null);

        if ($pendingUid <= 0 || $candidateUid !== $pendingUid) {
            return 0;
        }

        return 200;
    }

    private function isActiveLogin(): bool
    {
        return LoginType::tryFrom(self::stringFromMixed($this->login['status'] ?? null)) === LoginType::LOGIN;
    }

    private function hasPendingLoginForCurrentMode(): bool
    {
        $pendingLogin = $this->getPendingLogin();
        if (!is_array($pendingLogin)) {
            return false;
        }

        return self::stringFromMixed($pendingLogin['context'] ?? null) === $this->resolveExpectedContext();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingLogin(): ?array
    {
        $request = $this->getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $pendingLogin = $request->getAttribute(self::PENDING_LOGIN_ATTRIBUTE);
        if (!is_array($pendingLogin)) {
            return null;
        }

        $narrowed = [];
        foreach ($pendingLogin as $key => $value) {
            $narrowed[(string)$key] = $value;
        }

        return $narrowed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingUser(): ?array
    {
        $pendingLogin = $this->getPendingLogin();
        if (!is_array($pendingLogin)) {
            return null;
        }

        $pendingUser = $pendingLogin['user'] ?? null;
        if (!is_array($pendingUser)) {
            return null;
        }

        $narrowed = [];
        foreach ($pendingUser as $key => $value) {
            $narrowed[(string)$key] = $value;
        }

        return $narrowed;
    }

    private function resolveExpectedContext(): string
    {
        return str_ends_with($this->mode, 'BE') ? 'backend' : 'frontend';
    }

    private function getRequest(): ?ServerRequestInterface
    {
        $request = $this->authInfo['request'] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }

    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }

    private static function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
