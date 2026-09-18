<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Security;

/**
 * Deterministic narrowing from `mixed` to scalar types.
 *
 * PSR-7 request data, session data, `$GLOBALS` and JSON payloads are
 * `mixed`. These helpers keep the narrowing rules in one place instead of
 * repeating `is_string() ? ... : ''` at every boundary.
 */
final class MixedCaster
{
    public static function string(mixed $value, string $default = ''): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => (string)$value,
            default => $default,
        };
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && is_numeric($value), is_float($value) => (int)$value,
            is_bool($value) => $value ? 1 : 0,
            default => $default,
        };
    }

    /**
     * Narrow a `mixed` value to a string-keyed array (JSON objects, session
     * payloads, request attributes). Returns null for anything that is not
     * an array; integer keys are kept as their string form.
     *
     * @return array<string, mixed>|null
     */
    public static function stringKeyedArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $narrowed = [];
        foreach ($value as $key => $item) {
            $narrowed[(string)$key] = $item;
        }

        return $narrowed;
    }
}
