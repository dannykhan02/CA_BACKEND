<?php

namespace App\Support;

/**
 * Reads billing settings from the environment without letting a typo change behaviour or crash a request. An unset or empty value is
 * the default, silently. A value that is not usable (not numeric, out of range) also becomes the default and is remembered, so the
 * app can log ONE warning naming the variable (never its value is needed to act on it). Used from config files at load time.
 */
class ConfigEnv
{
    /** @var array<string,string> variable => why it was ignored */
    private static array $invalid = [];

    public static function invalid(): array
    {
        return self::$invalid;
    }

    public static function reset(): void
    {
        self::$invalid = [];
    }

    /** A number in [$min, $max] (with $min excluded when $minExclusive); anything else is the default. */
    public static function number(string $name, mixed $raw, float $default, float $min, float $max, bool $minExclusive = false): float
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (! is_numeric($raw)) {
            return self::reject($name, 'not a number', $default);
        }
        $value = (float) $raw;
        if (! is_finite($value) || $value > $max || $value < $min || ($minExclusive && $value <= $min)) {
            return self::reject($name, 'out of range', $default);
        }

        return $value;
    }

    /** A whole number of at least $min; null/empty is $default. Anything else is $default too. */
    public static function integer(string $name, mixed $raw, ?int $default, int $min): ?int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (! is_numeric($raw) || (string) (int) $raw !== (string) (0 + $raw)) {
            return self::reject($name, 'not a whole number', $default);
        }
        if ((int) $raw < $min) {
            return self::reject($name, 'below the minimum', $default);
        }

        return (int) $raw;
    }

    /** One of $allowed (case-insensitive); null/empty is $default, and so is anything else. */
    public static function choice(string $name, mixed $raw, string $default, array $allowed): string
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        $value = strtolower(trim((string) $raw));

        return in_array($value, $allowed, true) ? $value : self::reject($name, 'not one of '.implode(', ', $allowed), $default);
    }

    private static function reject(string $name, string $why, mixed $default): mixed
    {
        self::$invalid[$name] = $why;

        return $default;
    }
}
