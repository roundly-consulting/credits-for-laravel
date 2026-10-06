<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * An owner's balance summed across several named buckets, or across every bucket it owns.
 */
final readonly class GetCreditsTotalAction
{
    /**
     * `$buckets` null sums every bucket; a list sums those (names de-duplicated, an empty
     * list is zero). `$at` limits the sum to rows recorded up to that moment. Each bucket fits
     * the signed 64-bit ledger, but a sum of several may not: that throws AmountOverflow
     * rather than a saturated or wrapped number.
     *
     * @param  array<int, string>|null  $buckets
     *
     * @throws AmountOverflow
     */
    public function execute(Model&Creditable $creditable, ?array $buckets = null, ?CarbonInterface $at = null): int
    {
        // Through the owner's relation: the same connection and model class as the write.
        $query = $creditable->credits()->getQuery();

        if ($buckets !== null) {
            $buckets = array_values(array_unique($buckets));

            if ($buckets === []) {
                return 0;
            }

            $query->buckets($buckets);
        }

        if ($at !== null) {
            $query->upTo($at);
        }

        return $this->sum($query, $buckets);
    }

    /**
     * The driver's raw sum — a `numeric` / `DECIMAL` string on pgsql / MySQL, an int on
     * SQLite, which raises "integer overflow" itself — narrowed to an int only when it fits.
     *
     * @param  Builder<Credit>  $query
     * @param  array<int, string>|null  $buckets
     *
     * @throws AmountOverflow
     */
    private function sum(Builder $query, ?array $buckets): int
    {
        try {
            $sum = $query->sum('amount');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'integer overflow')) {
                throw LedgerBounds::totalOverflow($buckets);
            }

            throw $exception;
        }

        $exact = is_float($sum) ? sprintf('%.0f', $sum) : (string) $sum;

        return LedgerBounds::total($exact, $buckets);
    }
}
