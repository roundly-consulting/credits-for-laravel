<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\CreditsServiceProvider;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Credits ships exactly one CREATE and zero foreign keys — the ledger's owner is a
 * polymorphic `morphs('creditable')`, which is deliberately unconstrained because a host's
 * creditable entity can live in any table.
 *
 * That shape changes what is worth pinning here, and it is worth being explicit about why:
 *
 *  - **M (`toHaveRunnableMigrationOrder`) is not adopted.** With one migration and no FK
 *    edges there is no order to get wrong. `foreignKeys: 0` would pin a number that cannot
 *    change without a schema change, over a directory with a single file.
 *  - **The R negative control (`toRejectBrokenOrderOnConnection`) is not adoptable.** It
 *    asserts the engine *refuses* a reordered set — but reversing a one-file list is the
 *    same list, and with no foreign keys Postgres has nothing to refuse. It would fail
 *    loudly by design ("the engine accepted the broken order"), which is the assertion
 *    working correctly against a package it does not fit, not a red to chase.
 *
 * What remains is the half that does bite: the DDL itself has to be something a real
 * engine accepts.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5, on
 * three packages). `count: 1` pins the file count so neither check can pass over an empty
 * or relocated directory.
 */
it('never auto-loads its migration — the host publishes it', function (): void {
    expect(CreditsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migration timestamp-injected into the host', function (): void {
    expect(CreditsServiceProvider::class)->toPublishMigrationsTimestamped('credits-migrations', 1);
});

/**
 * R — the real-engine proof. Credits' DDL had never met a real engine before this row: the
 * suite ran on SQLite for the package's whole life, which is precisely how a `FOR UPDATE`
 * on an aggregate shipped. `migrations: 1` pins the count, and the expectation additionally
 * fails a set that "applies cleanly" while creating no tables — an empty `up()` otherwise
 * passes and proves nothing.
 */
it('applies its migration on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 1);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The `jsonb` meta column and the configurable primary key are the two bits of this schema
 * the drivers genuinely render differently (`jsonb` vs sqlite text; `bigserial` vs sqlite's
 * INTEGER rowid alias). Pinning a round-trip on whatever engine the leg configured is what
 * proves the column types are usable rather than merely creatable.
 *
 * Asserted key-by-key, not against a whole literal array: Postgres `jsonb` sorts object keys
 * by (length, bytes), so `toBe(['campaign' => ..., 'tier' => ...])` would compare insertion
 * order the engine never promised to keep. The value types still matter — `tier` must come
 * back the int 2, not "2" — so each key keeps a strict assertion.
 */
it('round-trips the ledger columns on the configured engine', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->modifyCredits(150, 'signup bonus', ['campaign' => 'launch', 'tier' => 2]);

    $fresh = $credit->fresh();

    expect($fresh->meta['campaign'] ?? null)->toBe('launch')
        ->and($fresh->meta['tier'] ?? null)->toBe(2)
        ->and($fresh->amount)->toBe(150)
        ->and($fresh->id)->toBeInt()
        ->and($user->creditsBalance())->toBe(150)
        // The driver actually under test, so a leg that quietly stayed on sqlite is visible
        // in the failure rather than passing as a "postgres" run.
        ->and(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});
