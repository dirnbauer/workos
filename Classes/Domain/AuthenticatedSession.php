<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Domain;

use WorkOS\Resource\AuthenticateResponse;
use WorkOS\Resource\User;

/**
 * The outcome of a successful WorkOS authentication: the user, the WorkOS
 * session it opened (ended again when the TYPO3 session logs out) and, for
 * a session a WorkOS administrator started through impersonation, who did.
 */
final readonly class AuthenticatedSession
{
    public function __construct(
        public User $user,
        public ?string $sessionId = null,
        public ?string $impersonatorEmail = null,
        public ?string $impersonationReason = null,
    ) {}

    public static function fromResponse(AuthenticateResponse $response, User $user, ?string $sessionId): self
    {
        return new self(
            user: $user,
            sessionId: $sessionId,
            impersonatorEmail: $response->impersonator?->email,
            impersonationReason: $response->impersonator?->reason,
        );
    }

    public function isImpersonated(): bool
    {
        return $this->impersonatorEmail !== null;
    }
}
