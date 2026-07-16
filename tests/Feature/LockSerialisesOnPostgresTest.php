<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The correctness bar for the `FOR UPDATE`-with-aggregate fix.
 *
 * Making the invalid SQL legal is worthless if it stops locking: the whole reason the
 * overdraft guard reads under a lock is to serialise two racing debits, so a second
 * spender blocks rather than deciding against a stale balance. Locking the wrong rows, or
 * a lock released before the aggregate, silently reintroduces the overdraft race — and no
 * single-connection test can tell the difference.
 *
 * So this drives a **real second connection**. Postgres is the only place it can be
 * proven: SQLite compiles the lock to an empty string, which is exactly why the bug hid
 * there for the package's whole life.
 *
 * Method: hold the guard's locked read open in a transaction, then have a second
 * connection attempt the same locked read under a short `lock_timeout`. If the rows are
 * genuinely locked it times out; the unlocked read underneath is the control that proves
 * the timeout is the lock and not a broken fixture.
 */
$onPostgres = fn (): bool => DriverMatrix::driver() !== 'pgsql';

beforeEach(function (): void {
    // A second, independent connection to the same Postgres database — a real concurrent
    // session, not another handle on the transaction under test.
    config()->set('database.connections.rival', DriverMatrix::connectionConfig('pgsql'));
    DB::purge('rival');
});

it('blocks a rival session on the rows the guard locked', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    DB::connection('rival')->statement("set lock_timeout = '400ms'");

    DB::transaction(function () use ($user): void {
        // The guard's locked read, exactly as ModifyCreditsAction takes it.
        $balance = app(GetCreditsBalanceAction::class)->execute($user, lockForUpdate: true);

        expect($balance)->toBe(100)
            ->and(DB::transactionLevel())->toBe(1);

        // The control: an UNLOCKED read of the same rows from the rival session sails
        // through. Postgres readers never block, so this proves the session is healthy
        // and the timeout below is the row lock — not a dead connection.
        expect((int) DB::connection('rival')->table('credits')->sum('amount'))->toBe(100);

        // The proof: the same rows, requested FOR UPDATE by the rival session, cannot be
        // had while this transaction holds them. Without the lock this returns instantly.
        expect(fn (): mixed => DB::connection('rival')
            ->table('credits')
            ->where('creditable_id', $user->id)
            ->lockForUpdate()
            ->pluck('amount'))
            ->toThrow(QueryException::class, 'lock timeout');
    });

    // Released on commit, never before: the rival session can now take the rows. This is
    // the other half — a lock held too briefly is as broken as no lock at all.
    expect(DB::connection('rival')->table('credits')->lockForUpdate()->pluck('amount'))
        ->toHaveCount(1);
})->skip($onPostgres, 'the lock is only observable on a real engine');

/**
 * The lock must cover the rows the guard's sum is derived from. A `FOR UPDATE` that
 * locked the wrong set — or an empty set — would leave the race wide open while looking
 * green: a rival debit would sail past the guard.
 */
it('locks every ledger row the balance is derived from', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(60);
    $user->modifyCredits(40);

    DB::connection('rival')->statement("set lock_timeout = '400ms'");

    DB::transaction(function () use ($user): void {
        expect(app(GetCreditsBalanceAction::class)->execute($user, lockForUpdate: true))->toBe(100);

        // Both ledger rows are locked, not just the one the sum happened to touch last.
        foreach ($user->credits()->pluck('id') as $id) {
            expect(fn (): mixed => DB::connection('rival')
                ->table('credits')
                ->where('id', $id)
                ->lockForUpdate()
                ->pluck('amount'))
                ->toThrow(QueryException::class, 'lock timeout');
        }
    });
})->skip($onPostgres, 'the lock is only observable on a real engine');
