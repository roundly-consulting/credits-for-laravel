<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * The default. A credit's id is what another package's polymorphic column points *at*, and
 * every raw `morphs()` in the fleet emits an unsigned bigint — so a non-bigint default here
 * makes credits unrelatable on any engine that actually checks types. It shipped as `uuid`.
 */
it('defaults to an auto-incrementing bigint primary key', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    expect($credit->id)->toBeInt()
        ->and($credit->getKeyType())->toBe('int')
        ->and($credit->getIncrementing())->toBeTrue()
        ->and($credit->uniqueIds())->toBe([]);
});

/**
 * Named per driver rather than normalised: `integer` is sqlite's rowid alias, `int8`/`bigint`
 * are Postgres'. Asserting the driver's own spelling is what makes the sqlite leg and the
 * real-engine leg both meaningful — and neither may ever report a string type, which is the
 * shape that shipped.
 */
it('emits an integer id column by default', function (): void {
    expect(Schema::getColumnType('credits', 'id'))->toBeIn(['integer', 'bigint', 'int8']);
});

it('auto-increments monotonically so ids order by insertion', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $ids = collect(range(1, 25))
        ->map(fn (int $n): int|string => $user->credits()->create(['amount' => $n])->id)
        ->all();

    expect($ids)->toBe(array_values(collect($ids)->sort()->all()));
});

/**
 * The whole point of the default: the id fits a bigint morph column. This is the
 * `posts`↔`likes` vector in credits' own shape — `anything↔credits` is the same bug.
 */
it('produces an id a bigint morph column can hold', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    Schema::create('bigint_morph_probe', function ($table): void {
        $table->id();
        $table->morphs('subject');
    });

    $stored = Credit::query()->getConnection()->table('bigint_morph_probe')->insertGetId([
        'subject_type' => $credit->getMorphClass(),
        'subject_id' => $credit->getKey(),
    ]);

    expect($stored)->toBeGreaterThan(0);
});
