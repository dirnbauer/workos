<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Exception;

/**
 * Thrown when WorkOS returns `email_verification_required` during
 * authentication. Carries the handshake data the caller needs to
 * complete the verification flow:
 *  - pendingAuthenticationToken -> pass back to
 *    `userManagement.authenticateWithEmailVerification`
 *  - email                      -> shown to the user
 *  - emailVerificationId        -> WorkOS verification resource id
 *  - userId (optional)          -> WorkOS user id, useful to resend
 */
final class EmailVerificationRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly string $pendingAuthenticationToken,
        public readonly string $email,
        public readonly string $emailVerificationId = '',
        public readonly string $userId = '',
        string $message = 'Email ownership must be verified before authentication.',
    ) {
        parent::__construct($message, 1744277830);
    }
}
