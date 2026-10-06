<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * The signed 64-bit bounds of the ledger, as the refusals the actions and CreditsFake share:
 * one place decides that a change, a `setTo()` delta, a balance or a total does not fit, and
 * words the AmountOverflow — so the fake refuses exactly what the real ledger refuses.
 *
 * @internal
 */
final class LedgerBounds
{
    /**
     * The bucket's balance after a change of `$amount` from `$available`.
     *
     * @throws AmountOverflow when it does not fit; the change must not be written
     */
    public static function balanceAfter(int $available, int $amount, string $bucket): int
    {
        $exact = Int64::add($available, $amount);

        return Int64::toInt($exact) ?? throw new AmountOverflow(
            "The credits balance of bucket [{$bucket}] would be [{$exact}] after this change, which does not fit a 64-bit integer; nothing was written.",
        );
    }

    /**
     * The change that takes the bucket from `$balance` to `$target`.
     *
     * @throws AmountOverflow when it does not fit; nothing must be written
     */
    public static function delta(int $target, int $balance, string $bucket): int
    {
        $exact = Int64::subtract($target, $balance);

        return Int64::toInt($exact) ?? throw new AmountOverflow(sprintf(
            'Setting the credits balance of bucket [%s] to [%d] needs a change of [%s], which does not fit a 64-bit integer; nothing was written.',
            $bucket,
            $target,
            $exact,
        ));
    }

    /**
     * The driver's raw sum of `amount` — a `numeric` / `DECIMAL` string on pgsql / MySQL, an int
     * on SQLite — as an exact integer string, never cast (a cast caps it at PHP_INT_MAX). Null
     * when the driver refused the sum itself: SQLite raises "integer overflow".
     *
     * @param  Builder<Credit>  $query
     */
    public static function exactSum(Builder $query): ?string
    {
        try {
            $sum = $query->sum('amount');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'integer overflow')) {
                return null;
            }

            throw $exception;
        }

        return is_float($sum) ? sprintf('%.0f', $sum) : (string) $sum;
    }

    /**
     * A bucket's exact balance, narrowed to an int. Every change keeps it inside int64, but a
     * point-in-time read of rows written out of `created_at` order can sum past it.
     *
     * @throws AmountOverflow when it does not fit
     */
    public static function balance(string $exact, string $bucket): int
    {
        return Int64::toInt($exact) ?? throw self::balanceOverflow($bucket, $exact);
    }

    /**
     * The refusal of a balance; `$exact` is null when the driver refused the sum itself.
     */
    public static function balanceOverflow(string $bucket, ?string $exact = null): AmountOverflow
    {
        return new AmountOverflow(
            "The credits balance of bucket [{$bucket}]"
            .($exact === null ? '' : " is [{$exact}], which").' does not fit a 64-bit integer.',
        );
    }

    /**
     * An exact total, narrowed to an int.
     *
     * @param  array<int, string>|null  $buckets  the summed buckets, null for every bucket
     *
     * @throws AmountOverflow when it does not fit
     */
    public static function total(string $exact, ?array $buckets): int
    {
        return Int64::toInt($exact) ?? throw self::totalOverflow($buckets, $exact);
    }

    /**
     * The refusal of a total; `$exact` is null when the driver refused the sum itself.
     *
     * @param  array<int, string>|null  $buckets
     */
    public static function totalOverflow(?array $buckets, ?string $exact = null): AmountOverflow
    {
        return new AmountOverflow(
            'The credits total across '.($buckets === null ? 'every bucket' : 'buckets ['.implode(', ', $buckets).']')
            .($exact === null ? '' : " is [{$exact}], which").' does not fit a 64-bit integer.',
        );
    }
}
