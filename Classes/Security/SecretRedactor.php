<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

/**
 * Redact secrets (WorkOS API keys, client secrets, bearer tokens)
 * before they reach log files or user-facing responses.
 *
 * Intentionally conservative: we'd rather over-redact a noisy
 * message than leak a real secret into persistent storage.
 */
final class SecretRedactor
{
    private const PATTERNS = [
        // WorkOS API keys (live + test)
        '/sk_(live|test)_[A-Za-z0-9]{16,}/',
        // WorkOS client IDs (less sensitive but still internal)
        '/client_[A-Za-z0-9]{16,}/',
        // Generic bearer tokens
        '/Bearer\s+[A-Za-z0-9._\-]{20,}/i',
        // Common Authorization header JWT shape
        '/eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',
    ];

    public static function redact(string $message): string
    {
        $replaced = preg_replace(self::PATTERNS, '[REDACTED]', $message);
        return is_string($replaced) ? $replaced : $message;
    }
}
