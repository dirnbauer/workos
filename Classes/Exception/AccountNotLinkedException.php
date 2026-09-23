<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Exception;

use Webconsulting\WorkosAuth\Domain\LoginContext;

/**
 * A WorkOS account authenticated, but no active TYPO3 account belongs to it
 * and none may be created. Carries what an administrator needs to link it.
 */
final class AccountNotLinkedException extends \RuntimeException
{
    public function __construct(
        public readonly LoginContext $context,
        public readonly string $email,
        public readonly string $workosUserId,
        string $message,
        int $code,
    ) {
        parent::__construct($message, $code);
    }
}
