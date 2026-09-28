<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * Serialises every balance-dependent write of one owner on the owner's own row.
 *
 * Locking the ledger rows alone is not enough on Postgres: under READ COMMITTED a
 * `SELECT … FOR UPDATE` that had to wait for a racing write answers from the snapshot taken
 * when it *started*, so it never sees the ledger row that write inserted, and decides against
 * the old balance. Nor can it lock anything in an empty bucket. Waiting on the owner row first
 * means the balance read that follows starts only after the racing write committed, and sees
 * its row. Call it inside the transaction that reads the balance and writes the ledger.
 *
 * @internal
 */
final class OwnerLock
{
    public function acquire(Model&Creditable $creditable): void
    {
        $creditable->newQueryWithoutScopes()
            ->whereKey($creditable->getKey())
            ->lockForUpdate()
            ->first([$creditable->getKeyName()]);
    }
}
