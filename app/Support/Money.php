<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money as canonical decimal strings ("1000.00") with bcmath, scale 2. Never floats.
 * Inputs with more than two decimals are rejected by validation, never rounded silently.
 */
final class Money
{
    /** Largest amount accepted for a single entry (provisional business limit; DECIMAL(19,2) holds more). */
    public const MAX = '999999999.99';

    /** Accepts "1000", "1000.5" or "1000.50"; no signs, separators, exponents or leading zeros. */
    public const PATTERN = '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/';

    public static function isValidInput(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public static function normalize(string $value): string
    {
        if (! self::isValidInput($value) && ! preg_match('/^-?[0-9]{1,17}\.[0-9]{2}$/', $value)) {
            throw new InvalidArgumentException('Invalid money value.');
        }

        return bcadd($value, '0', 2);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), 2);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), 2);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), 2);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0.00') > 0;
    }

    public static function withinLimit(string $value): bool
    {
        return self::compare($value, self::MAX) <= 0;
    }

    /** "1234.50" → "1.234,50" for Portuguese messages (no currency symbol). */
    public static function display(string $value): string
    {
        $normalized = self::normalize($value);
        $negative = str_starts_with($normalized, '-');
        [$integer, $decimals] = explode('.', ltrim($normalized, '-'));

        return ($negative ? '-' : '').number_format((int) $integer, 0, ',', '.').','.$decimals;
    }

    /**
     * Splits a total into n parts of whole cents; the cents left over go to the last part (ADR-032),
     * e.g. 100.00 / 3 → 33.33 + 33.33 + 33.34. The parts always add up exactly to the total.
     *
     * @return list<string>
     */
    public static function split(string $total, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('At least one part.');
        }
        $cents = bcmul(self::normalize($total), '100', 0);
        $base = bcdiv($cents, (string) $parts, 0);
        $result = array_fill(0, $parts, bcdiv($base, '100', 2));
        $result[$parts - 1] = bcdiv(bcsub($cents, bcmul($base, (string) ($parts - 1), 0), 0), '100', 2);

        return $result;
    }
}
