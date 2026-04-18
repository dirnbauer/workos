<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Security;

/**
 * Deterministic narrowing from `mixed` to scalar types.
 *
 * PHP / PSR-7 boundaries routinely return `mixed` (parsed request
 * bodies, query params, session data, `$GLOBALS`, database rows,
 * JSON-decoded payloads). Cast helpers keep that narrowing in one
 * place so PHPStan at max level can track the flow without needing
 * an explicit cast in every call site.
 */
final class MixedCaster
{
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return $default;
    }
}
