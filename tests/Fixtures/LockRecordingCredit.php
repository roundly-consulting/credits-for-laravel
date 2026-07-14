<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Credits\Models\Credit;

/**
 * A `credits.model` override that records every `lockForUpdate()` the package takes,
 * together with the transaction depth it was taken at.
 *
 * SQLite compiles `lockForUpdate()` to an empty string, so the row lock leaves no trace
 * in the query log. Recording it on the builder is the only way to pin, in the suite,
 * that the overdraft guard reads the ledger under a lock — which is what serialises two
 * racing debits on a real RDBMS.
 */
final class LockRecordingCredit extends Credit
{
    /**
     * The transaction depth at each `lockForUpdate()` taken since the last reset.
     *
     * @var list<int>
     */
    public static array $locks = [];

    protected $table = 'credits';

    public static function resetLocks(): void
    {
        self::$locks = [];
    }

    /**
     * @param  QueryBuilder  $query
     * @return LockRecordingBuilder<static>
     */
    public function newEloquentBuilder($query): LockRecordingBuilder
    {
        return new LockRecordingBuilder($query);
    }
}
