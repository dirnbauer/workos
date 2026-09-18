<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Security;

/**
 * Maps raw WorkOS / SDK error text to stable translation keys.
 *
 * Callers translate the returned key; the original message is logged via
 * SecretRedactor, so it never leaks into redirects, session flash or
 * access logs.
 */
final class WorkosErrorMessageResolver
{
    public function resolveAuthentication(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'password'), str_contains($lower, 'credentials'), str_contains($lower, 'unauthorized') => 'error.invalidEmailOrPassword',
            str_contains($lower, 'magic') && (str_contains($lower, 'not enabled') || str_contains($lower, 'disabled')) => 'error.magicAuthDisabled',
            str_contains($lower, 'authentication_method_not_allowed'), str_contains($lower, 'method_not_allowed') => 'error.methodNotAllowed',
            str_contains($lower, 'code') && (str_contains($lower, 'expired') || str_contains($lower, 'invalid')) => 'error.invalidOrExpiredCode',
            str_contains($lower, 'user_not_found'), str_contains($lower, 'not found') => 'error.userNotFound',
            default => 'error.generic',
        };
    }

    public function resolveSignUp(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'password_too_short'), str_contains($lower, 'too short') => 'error.passwordTooShort',
            str_contains($lower, 'password_too_weak'), str_contains($lower, 'too weak'), str_contains($lower, 'unguessable') => 'error.passwordTooWeak',
            str_contains($lower, 'pwned'), str_contains($lower, 'breached'), str_contains($lower, 'compromised') => 'error.passwordBreached',
            str_contains($lower, 'already exists'), str_contains($lower, 'duplicate'), str_contains($lower, 'user_exists') => 'error.userAlreadyExists',
            str_contains($lower, 'password') && str_contains($lower, 'invalid') => 'error.passwordInvalid',
            default => 'error.generic',
        };
    }

    /**
     * Account Center password change: same WorkOS password policy errors as
     * sign-up, but with the plugin's own flash labels.
     */
    public function resolvePasswordChange(string $message): string
    {
        return match ($this->resolveSignUp($message)) {
            'error.passwordTooShort' => 'account.flash.passwordTooShort',
            'error.passwordTooWeak' => 'account.flash.passwordTooWeak',
            'error.passwordBreached' => 'account.flash.passwordBreached',
            default => 'account.flash.passwordFailed',
        };
    }

    public function resolveInvitation(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'forbidden_organization') => 'team.flash.forbidden',
            str_contains($lower, 'already') && (str_contains($lower, 'invited') || str_contains($lower, 'exists')) => 'team.flash.inviteAlreadyExists',
            str_contains($lower, 'invalid_email'), str_contains($lower, 'invalid email') => 'team.flash.inviteInvalidEmail',
            default => 'team.flash.inviteFailed',
        };
    }
}
