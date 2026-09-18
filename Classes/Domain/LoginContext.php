<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Domain;

/**
 * The two TYPO3 login contexts a WorkOS identity can be linked to.
 * The value is what `tx_workosauth_identity.login_context` stores.
 */
enum LoginContext: string
{
    case Frontend = 'frontend';
    case Backend = 'backend';

    /**
     * TYPO3 user table the context authenticates against
     * (also stored in `tx_workosauth_identity.user_table`).
     */
    public function userTable(): string
    {
        return match ($this) {
            self::Frontend => 'fe_users',
            self::Backend => 'be_users',
        };
    }

    /**
     * Request-body field TYPO3 core reads to detect an active login.
     */
    public function loginStatusField(): string
    {
        return match ($this) {
            self::Frontend => 'logintype',
            self::Backend => 'login_status',
        };
    }

    /**
     * Request-token scope TYPO3 core expects during active login processing.
     */
    public function coreRequestTokenScope(): string
    {
        return 'core/user-auth/' . $this->loginTypeAbbreviation();
    }

    /**
     * `FE` / `BE` as used by TYPO3 auth service modes (`getUserFE`, `authUserBE`, ...)
     * and `AbstractUserAuthentication::$loginType`.
     */
    public function loginTypeAbbreviation(): string
    {
        return match ($this) {
            self::Frontend => 'fe',
            self::Backend => 'be',
        };
    }

    /**
     * Resolve the context from a TYPO3 auth service mode such as `authUserBE`
     * or from `AbstractUserAuthentication::$loginType` (`FE` / `BE`).
     */
    public static function fromLoginType(string $loginTypeOrMode): self
    {
        return str_ends_with(strtoupper($loginTypeOrMode), 'BE') ? self::Backend : self::Frontend;
    }
}
