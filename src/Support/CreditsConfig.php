<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Support\RoundingModes;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict reads of the non-boolean `credits.*` settings. A key that is not set — absent, null or
 * blank (`''` or whitespace, a host's `KEY=`) — takes its default; a present value of the wrong
 * shape — `'five'` for the floor, an array for the bucket, a string where the resolver list
 * belongs — throws {@see InvalidConfigurationException} naming the key. A typo is never cast to
 * 0 or swapped for the default.
 *
 * @internal
 */
final class CreditsConfig
{
    /** The balance floor a deduction may not cross when overdraft is disallowed. */
    public static function minimumBalance(): int
    {
        return Config::integer('credits.minimum_balance', 0);
    }

    /** Decimal places of the stored minor units, 0..{@see MinorUnits::MAX_SCALE}. */
    public static function scale(): int
    {
        return Config::integer('credits.scale', 0, min: 0, max: MinorUnits::MAX_SCALE);
    }

    /** The bucket a call without one reads and writes. */
    public static function defaultBucket(): string
    {
        return self::blank(config('credits.default_bucket')) ? 'default' : Config::requireString('credits.default_bucket');
    }

    /**
     * The display rounding mode; not set means `half_away_from_zero`.
     *
     * @throws InvalidMoneyConfiguration when the value names no mode
     */
    public static function rounding(): RoundingMode
    {
        return RoundingModes::fromValue(config('credits.rounding'), 'credits.rounding', RoundingMode::HalfAwayFromZero);
    }

    /**
     * The `credits:modify` resolvers, each a callable taking the `$modify` callback.
     *
     * @return list<callable>
     */
    public static function modifiable(): array
    {
        $modifiable = config('credits.modifiable');
        $modifiable = self::blank($modifiable) ? [] : $modifiable;

        if (! is_array($modifiable)) {
            throw new InvalidConfigurationException('Configuration value [credits.modifiable] must be a list of closures, ['.get_debug_type($modifiable).'] given.');
        }

        foreach ($modifiable as $resolver) {
            if (! is_callable($resolver)) {
                throw new InvalidConfigurationException('Configuration value [credits.modifiable] must be a list of closures, an entry of type ['.get_debug_type($resolver).'] given.');
            }
        }

        return array_values($modifiable);
    }

    /** Not set: absent, null or a blank string (`''` or whitespace — a host's `KEY=`). */
    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
