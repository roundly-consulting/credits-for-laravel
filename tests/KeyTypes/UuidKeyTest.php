<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The seam's uuid leg. A config-driven key type that only works on its default is not a
 * seam — the migration and the model must agree on the same config value, or the package
 * mints an id its own column cannot hold.
 */
it('mints and stores a uuid primary key when configured for uuid', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    expect($credit->id)->toBeString()->toHaveLength(36)
        ->and($credit->getKeyType())->toBe('string')
        ->and($credit->getIncrementing())->toBeFalse()
        ->and($credit->uniqueIds())->toBe(['id'])
        ->and($credit->fresh()->id)->toBe($credit->id);
});

it('round-trips a uuid-keyed credit through the balance query', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->credits()->create(['amount' => 150]);
    $user->credits()->create(['amount' => -50]);

    expect($user->creditsBalance())->toBe(100);
});

/**
 * The negative control, and the documented cost of the seam in executable form.
 *
 * A green key-type test proves nothing until you have watched the engine reject the broken
 * shape. `uuid` is a supported key type, but it is supported only for a host whose morph
 * targets are ALL uuid-keyed: a raw `morphs()` column — which is what 22 of the fleet's
 * packages emit — is an unsigned bigint and cannot hold this id. That is the exact failure
 * that shipped as the default, and it is why the default is now bigint.
 *
 * Postgres-only on purpose: SQLite's type affinity stores the uuid string in an INTEGER
 * column without complaint, so this test CANNOT fail there. That silent acceptance is what
 * hid the bug fleet-wide, and a skip here is the honest report of it.
 */
it('cannot be stored in a bigint morph column — the cost of a non-bigint key', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $credit = $user->credits()->create(['amount' => 50]);

    Schema::create('bigint_morph_probe', function ($table): void {
        $table->id();
        $table->morphs('subject');
    });

    expect(fn () => Credit::query()->getConnection()->table('bigint_morph_probe')->insert([
        'subject_type' => $credit->getMorphClass(),
        'subject_id' => $credit->getKey(),
    ]))->toThrow(QueryException::class, 'invalid input syntax for type bigint');
})->skip(
    fn (): bool => DriverMatrix::driver() !== 'pgsql',
    'sqlite type affinity accepts the uuid silently — only a strict engine detects this',
);
