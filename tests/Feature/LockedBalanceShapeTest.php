<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * The shipped bug (2026-07-16): the overdraft guard read the balance with
 * `lockForUpdate()` applied to an **aggregate** — `select sum("amount") … for update`.
 *
 *   Postgres: ERROR: FOR UPDATE is not allowed with aggregate functions (SQLSTATE 0A000)
 *
 * Every spend or grant with overdraft protection on — the default — threw on any real
 * engine. It shipped because SQLite compiles `lockForUpdate()` to an empty string, so the
 * statement never reached an engine that could reject it. The suite even *recorded* the
 * lock with purpose-built fixtures and still could not see it: recording that a lock was
 * *asked for* says nothing about whether the SQL it produced is legal.
 *
 * These are the two halves of the guard, and they must both hold:
 *
 *  1. the locked read is **not** an aggregate — pinned here on SQLite through the
 *     recording grammar, which is the only way to see a lock this driver erases;
 *  2. the lock is still real — same statement, transaction depth 1 (the datum that
 *     condemned `LockedUpdate`: a lock one level too deep is a silent non-lock), taken
 *     after the owner-lock bump that serialises the owner.
 *
 * The behavioural other half — that the guard actually rejects an overdraft on a real
 * engine — is BalanceConcurrencyTest, which runs on whatever driver the leg configured.
 */
beforeEach(function (): void {
    LockRecorder::flush();
});

/**
 * Variant B of the lock recorder: `LockRecordingGrammar` extends the SQLite grammar, so
 * this pins the shape on the sqlite leg. On the pgsql leg the engine itself is the
 * assertion — it rejects the aggregate form outright, which is what BalanceConcurrencyTest
 * observes.
 */
it('bumps the owner lock, then locks the ledger rows without an aggregate the engine would reject', function (): void {
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::listenForMarkers();

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    LockRecorder::flush();
    DB::enableQueryLog();

    $user->modifyCredits(-40);

    $locks = LockRecorder::recorded();
    $statements = array_map(static fn (array $query): string => strtolower((string) $query['query']), DB::getQueryLog());
    DB::disableQueryLog();

    $bump = array_key_first(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'update "credit_locks"')));
    $read = array_key_first(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "credits"')));

    // The owner is serialised by an UPDATE of its lock row — an update, not a mere row lock,
    // so a snapshot isolation level fails the racing transaction instead of letting it
    // decide on stale data (see RacingDebitsOnPostgresTest) — and it comes first.
    expect($bump)->not->toBeNull()
        ->and($read)->not->toBeNull()
        ->and($bump)->toBeLessThan($read)
        // Then exactly one real FOR UPDATE: the ledger read.
        ->and($locks)->toHaveCount(1)
        ->and($locks[0]['marker'])->toBe('lock-for-update')
        ->and(strtolower($locks[0]['sql']))->toContain('from "credits"')
        // The bug, pinned: `sum(...) ... for update` is invalid SQL on Postgres and MySQL
        // alike. The locked read must select the rows, not aggregate them.
        ->and(strtolower($locks[0]['sql']))->not->toContain('sum(')
        ->and(strtolower($locks[0]['sql']))->toContain('"amount"')
        // And it must be a lock that serialises: taken inside the guard's transaction, so a
        // racing debit blocks on it rather than reading a stale sum.
        ->and($locks[0]['transactionDepth'])->toBe(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'recording grammar is sqlite-only');
