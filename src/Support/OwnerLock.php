<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * Serialises every balance-changing write of one owner, on every isolation level.
 *
 * Each write bumps the owner's row in `credit_locks` with an UPDATE (inserting the row on the
 * owner's first write) inside the transaction that reads the balance and writes the ledger.
 * Merely *locking* a row (`SELECT … FOR UPDATE` on the owner, as this used to) is not enough:
 * under REPEATABLE READ the transaction's snapshot is taken at its first statement, before
 * the lock wait, so a waiter reads the balance from before the racing write and misses the
 * ledger row it inserted. Updating the row makes the race visible to the engine:
 *
 *  - READ COMMITTED: the UPDATE waits for the racing write; the balance read that follows is
 *    a new statement with a new snapshot, so it sees the racing row.
 *  - Postgres REPEATABLE READ / SERIALIZABLE: updating a row a racing transaction updated
 *    after our snapshot fails with a serialisation failure (SQLSTATE 40001) instead of
 *    proceeding on stale data. The actions run their transaction with {@see self::ATTEMPTS},
 *    so Laravel retries it with a fresh snapshot — unless a host transaction encloses it,
 *    where only the host can retry (the snapshot is the host's).
 *  - MySQL / MariaDB (REPEATABLE READ by default): the UPDATE and the ledger's `FOR UPDATE`
 *    read are current reads, which see the racing write's committed rows whatever the snapshot.
 *
 * @internal
 */
final class OwnerLock
{
    /**
     * How often a transaction that takes the lock is attempted. A serialisation failure or
     * deadlock on the lock row is retried; any other exception is not.
     */
    public const int ATTEMPTS = 5;

    public const string TABLE = 'credit_locks';

    public function acquire(Model&Creditable $creditable): void
    {
        $connection = $creditable->getConnection();
        $type = $creditable->getMorphClass();
        $id = $creditable->getKey();

        if ($this->bump($connection, $type, $id) > 0) {
            return;
        }

        // The owner's first write: create its row, then take it. A racing first write inserts
        // the same key; `insertOrIgnore` defers to it, and the bump below waits on it.
        $connection->table(self::TABLE)->insertOrIgnore(['creditable_type' => $type, 'creditable_id' => $id]);

        $this->bump($connection, $type, $id);
    }

    private function bump(ConnectionInterface $connection, string $type, mixed $id): int
    {
        return $connection->table(self::TABLE)
            ->where('creditable_type', $type)
            ->where('creditable_id', $id)
            ->increment('version');
    }
}
