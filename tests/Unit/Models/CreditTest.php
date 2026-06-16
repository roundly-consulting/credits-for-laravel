<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('uses a uuid primary key', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    expect($credit->id)->toBeString()->toHaveLength(36);
});

it('sums credit amounts for a creditable entity', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->credits()->create(['amount' => 100]);
    $user->credits()->create(['amount' => -30]);

    $balance = (new Credit)->balance($user);

    expect($balance)->toBe(70);
});

it('limits the balance to a point in time', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $old = $user->credits()->create(['amount' => 100]);
    $old->forceFill(['created_at' => now()->subDay()])->save();

    $user->credits()->create(['amount' => 50]);

    $balance = (new Credit)->balance($user, now()->subHour());

    expect($balance)->toBe(100);
});

it('soft deletes credits', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $credit = $user->credits()->create(['amount' => 10]);

    $credit->delete();

    expect($credit->trashed())->toBeTrue()
        ->and(Credit::query()->count())->toBe(0)
        ->and(Credit::withTrashed()->count())->toBe(1);
});
