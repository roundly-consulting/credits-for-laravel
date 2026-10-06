<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\LedgerCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\TenantUser;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * One ledger connection: the reads (balance, total), the write, the owner lock and the
 * transaction all run where the ledger rows live — the ledger model's own connection, else
 * the owner's. Reads used to go to the default connection while the write followed the
 * owner, so an owner on another connection read a balance of 0 (or another database's rows).
 *
 * @param  list<string>  $paths
 */
function migrateSecondConnection(string $connection, array $paths): void
{
    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    Artisan::call('migrate', [
        '--database' => $connection,
        '--path' => array_map(static fn (string $path): string => (string) realpath($path), $paths),
        '--realpath' => true,
    ]);
}

it('reads an owner on another connection from that connection', function (): void {
    migrateSecondConnection('tenant', [__DIR__.'/../../database/migrations', __DIR__.'/../database/migrations']);

    $user = TenantUser::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    expect(DB::connection('tenant')->table('credits')->count())->toBe(1)
        ->and(DB::table('credits')->count())->toBe(0)
        ->and($user->creditsBalance())->toBe(100)
        ->and($user->totalCreditsBalance())->toBe(100)
        ->and(Credits::balance($user))->toBe(100)
        ->and(Credits::total($user))->toBe(100);

    Credits::for($user)->deduct(10);

    expect($user->creditsBalance())->toBe(90);
});

it('guards an owner on another connection against its own ledger, not the default one', function (): void {
    migrateSecondConnection('tenant', [__DIR__.'/../../database/migrations', __DIR__.'/../database/migrations']);

    $user = TenantUser::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    // Another database's row for the same morph key must not fund the debit.
    DB::table('credits')->insert([
        'creditable_type' => $user->getMorphClass(),
        'creditable_id' => $user->getKey(),
        'bucket' => 'default',
        'amount' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): mixed => Credits::for($user)->deduct(300))
        ->toThrow(function (InsufficientCreditsException $e): void {
            expect($e->available)->toBe(100);
        })
        ->and(DB::connection('tenant')->table('credits')->count())->toBe(1)
        ->and($user->creditsBalance())->toBe(100);
});

it('writes a ledger model on its own connection inside the transaction that holds the lock', function (): void {
    migrateSecondConnection('ledger', [__DIR__.'/../../database/migrations']);
    config()->set('credits.model', LedgerCredit::class);

    $user = User::query()->create(['name' => 'Ada']);

    $levels = [];
    LedgerCredit::creating(function () use (&$levels): void {
        $levels[] = DB::connection('ledger')->transactionLevel();
    });

    $user->modifyCredits(5);

    expect($levels)->toBe([1])
        ->and((int) DB::connection('ledger')->table('credit_locks')->value('version'))->toBe(1)
        ->and(DB::table('credit_locks')->count())->toBe(0)
        ->and(DB::connection('ledger')->table('credits')->count())->toBe(1)
        ->and($user->creditsBalance())->toBe(5)
        ->and($user->totalCreditsBalance())->toBe(5);

    LedgerCredit::flushEventListeners();
});
