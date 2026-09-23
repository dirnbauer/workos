<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Exception;

/**
 * A WorkOS administrator impersonated a user, and the TYPO3 backend does
 * not accept impersonated sessions: they would turn "admin of the WorkOS
 * dashboard" into "any linked TYPO3 backend account, administrators included".
 */
final class ImpersonationNotAllowedException extends \RuntimeException {}
