<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Exception;

/**
 * WorkOS answered an authentication attempt with `email_verification_required`.
 * Carries the handshake data needed to finish the login with the emailed code.
 */
final class EmailVerificationRequiredException extends \RuntimeException
{
    public function __construct(
        /** Passed back to `authenticateWithEmailVerification()`. */
        public readonly string $pendingAuthenticationToken,
        /** Shown to the user. */
        public readonly string $email,
        /** WorkOS user id; enables "resend the code" when known. */
        public readonly string $userId = '',
    ) {
        parent::__construct('Email ownership must be verified before authentication.', 1744277830);
    }
}
