<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict reads of the non-boolean `credits.*` settings. An absent (null) key takes its default;
 * a present value of the wrong shape — `'five'` for the floor, a blank bucket, a string where
 * the resolver list belongs — throws {@see InvalidConfigurationException} naming the key. A
 * typo is never cast to 0 or swapped for the default.
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
        return config('credits.default_bucket') === null ? 'default' : Config::requireString('credits.default_bucket');
    }

    /**
     * The `credits:modify` resolvers, each a callable taking the `$modify` callback.
     *
     * @return list<callable>
     */
    public static function modifiable(): array
    {
        $modifiable = config('credits.modifiable') ?? [];

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
}
