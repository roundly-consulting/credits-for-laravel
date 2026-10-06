<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * One bucket's balance — the configured default unless named — optionally as of a point in
 * time, and optionally read under a row lock (the read every change decides on). Sums across
 * buckets are GetCreditsTotalAction.
 */
final readonly class GetCreditsBalanceAction
{
    public function execute(
        Model&Creditable $creditable,
        ?CarbonInterface $at = null,
        bool $lockForUpdate = false,
        ?string $bucket = null,
    ): int {
        $resolvedBucket = $bucket ?? CreditsConfig::defaultBucket();

        return $this->sum(
            $this->baseQuery($creditable)->bucket($resolvedBucket),
            $resolvedBucket,
            $at,
            $lockForUpdate,
        );
    }

    /**
     * @param  Builder<Credit>  $query
     *
     * @throws AmountOverflow when a point-in-time sum does not fit int64
     */
    private function sum(Builder $query, string $bucket, ?CarbonInterface $at, bool $lockForUpdate = false): int
    {
        $query = $query->when(
            value: ! is_null($at),
            // Through upTo(), which binds `$at` as an instant in the zone `created_at` is stored in.
            callback: fn (Builder $builder): Builder => $builder->upTo($at),
        );

        if ($lockForUpdate) {
            return $this->sumLockedRows($query);
        }

        // Exact, never cast: rows written out of `created_at` order can sum past int64 at `$at`.
        $exact = LedgerBounds::exactSum($query) ?? throw LedgerBounds::balanceOverflow($bucket);

        return LedgerBounds::balance($exact, $bucket);
    }

    /**
     * Lock the ledger rows, then add up exactly the rows that were locked.
     *
     * A locked balance cannot be a single statement: `SELECT sum(amount) … FOR UPDATE` is
     * rejected outright by Postgres ("FOR UPDATE is not allowed with aggregate functions",
     * SQLSTATE 0A000) and is invalid on MySQL too. Only SQLite tolerated it, by compiling
     * the lock away to an empty string — which is how it shipped.
     *
     * Selecting the amounts under the lock keeps the guard's meaning intact: the lock
     * covers precisely the rows the sum is derived from, and it is held to the end of the
     * enclosing transaction (never released between the read and the ledger write), so a
     * racing debit blocks on it instead of deciding against a stale balance.
     *
     * @param  Builder<Credit>  $query
     */
    private function sumLockedRows(Builder $query): int
    {
        return (int) $query->lockForUpdate()->pluck('amount')->sum();
    }

    /**
     * The owner's ledger rows through its own relation, so the read runs on the same
     * connection and model class as the write (see Support\LedgerConnection).
     *
     * @return Builder<Credit>
     */
    private function baseQuery(Model&Creditable $creditable): Builder
    {
        return $creditable->credits()->getQuery();
    }
}
