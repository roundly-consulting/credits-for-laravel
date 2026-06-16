<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('uses a uuid primary key', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    expect($credit->id)->toBeString()->toHaveLength(36);
});

it('casts amount to integer and meta to array', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => '42', 'meta' => ['k' => 'v']]);

    expect($credit->amount)->toBe(42)
        ->and($credit->meta)->toBe(['k' => 'v']);
});

it('soft deletes credits', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $credit = $user->credits()->create(['amount' => 10]);

    $credit->delete();

    expect($credit->trashed())->toBeTrue()
        ->and(Credit::query()->count())->toBe(0)
        ->and(Credit::withTrashed()->count())->toBe(1);
});

it('excludes soft-deleted rows from the balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 100]);
    $deducted = $user->credits()->create(['amount' => -30]);

    $deducted->delete();

    expect($user->creditsBalance())->toBe(100);
});
