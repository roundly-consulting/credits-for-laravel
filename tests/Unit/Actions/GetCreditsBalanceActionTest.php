<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('sums the ledger for a creditable', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 100]);
    $user->credits()->create(['amount' => -30]);

    expect(app(GetCreditsBalanceAction::class)->execute($user))->toBe(70);
});

it('isolates balances per creditable', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);
    $ada->credits()->create(['amount' => 100]);
    $bob->credits()->create(['amount' => 5]);

    expect(app(GetCreditsBalanceAction::class)->execute($ada))->toBe(100);
});

it('respects a point in time', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $old = $user->credits()->create(['amount' => 100]);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->credits()->create(['amount' => 50]);

    expect(app(GetCreditsBalanceAction::class)->execute($user, now()->subHour()))->toBe(100);
});

it('excludes soft-deleted rows', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 100]);
    $deducted = $user->credits()->create(['amount' => -30]);
    $deducted->delete();

    expect(app(GetCreditsBalanceAction::class)->execute($user))->toBe(100);
});
