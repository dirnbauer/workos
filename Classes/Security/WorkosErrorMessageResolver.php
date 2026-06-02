<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

/**
 * Maps raw WorkOS / SDK error text to stable translation keys.
 *
 * Callers translate the returned key; the original message should be
 * logged via SecretRedactor before resolving, so it never leaks into
 * redirects, session flash, or access logs.
 */
final class WorkosErrorMessageResolver
{
    public function resolveAuthentication(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'password') || str_contains($lower, 'credentials') || str_contains($lower, 'unauthorized')) {
            return 'error.invalidEmailOrPassword';
        }
        if (str_contains($lower, 'magic') && (str_contains($lower, 'not enabled') || str_contains($lower, 'disabled'))) {
            return 'error.magicAuthDisabled';
        }
        if (str_contains($lower, 'authentication_method_not_allowed') || str_contains($lower, 'method_not_allowed')) {
            return 'error.methodNotAllowed';
        }
        if (str_contains($lower, 'code') && (str_contains($lower, 'expired') || str_contains($lower, 'invalid'))) {
            return 'error.invalidOrExpiredCode';
        }
        if (str_contains($lower, 'user_not_found') || str_contains($lower, 'not found')) {
            return 'error.userNotFound';
        }

        return 'error.generic';
    }

    public function resolveSignUp(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'password_too_short') || str_contains($lower, 'too short')) {
            return 'error.passwordTooShort';
        }
        if (str_contains($lower, 'password_too_weak') || str_contains($lower, 'too weak') || str_contains($lower, 'unguessable')) {
            return 'error.passwordTooWeak';
        }
        if (str_contains($lower, 'pwned') || str_contains($lower, 'breached') || str_contains($lower, 'compromised')) {
            return 'error.passwordBreached';
        }
        if (str_contains($lower, 'already exists') || str_contains($lower, 'duplicate') || str_contains($lower, 'user_exists')) {
            return 'error.userAlreadyExists';
        }
        if (str_contains($lower, 'password') && str_contains($lower, 'invalid')) {
            return 'error.passwordInvalid';
        }

        return 'error.generic';
    }
}
