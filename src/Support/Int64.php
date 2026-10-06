<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use InvalidArgumentException;

/**
 * Exact signed 64-bit arithmetic for the ledger, on bcmath strings. PHP promotes an
 * overflowing `int + int` to a float — silently, and near the bottom of the range even
 * back to a wrong int — so a balance, a `setTo()` delta or a total is computed exactly
 * first and only then narrowed to an int.
 *
 * @internal
 */
final class Int64
{
    /** The exact sum of two ints, as an integer string. */
    public static function add(int $left, int $right): string
    {
        return bcadd((string) $left, (string) $right, 0);
    }

    /** The exact sum of any number of ints ("0" for none), as an integer string. */
    public static function sum(int ...$terms): string
    {
        $sum = '0';

        foreach ($terms as $term) {
            $sum = bcadd($sum, (string) $term, 0);
        }

        return $sum;
    }

    /** The exact difference of two ints, as an integer string. */
    public static function subtract(int $left, int $right): string
    {
        return bcsub((string) $left, (string) $right, 0);
    }

    /**
     * The integer string as an int, or null when it does not fit a signed 64-bit integer.
     * A decimal string (`"100.00"`, a driver's `numeric` sum) is truncated to its integer part.
     *
     * @throws InvalidArgumentException when the string is not a number
     */
    public static function toInt(string $integer): ?int
    {
        if (! is_numeric($integer)) {
            throw new InvalidArgumentException("[{$integer}] is not an integer.");
        }

        $integer = bcadd($integer, '0', 0);

        if (bccomp($integer, (string) PHP_INT_MAX, 0) > 0 || bccomp($integer, (string) PHP_INT_MIN, 0) < 0) {
            return null;
        }

        return (int) $integer;
    }
}
