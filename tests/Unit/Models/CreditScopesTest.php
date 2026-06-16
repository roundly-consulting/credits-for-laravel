<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('scopes to grants', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 100]);
    $user->credits()->create(['amount' => -40]);

    expect(Credit::query()->grants()->count())->toBe(1);
});

it('scopes to deductions', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 100]);
    $user->credits()->create(['amount' => -40]);

    expect(Credit::query()->deductions()->count())->toBe(1);
});

it('scopes up to a point in time', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $old = $user->credits()->create(['amount' => 10]);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->credits()->create(['amount' => 20]);

    expect(Credit::query()->upTo(now()->subHour())->count())->toBe(1);
});

it('scopes for a creditable', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);
    $ada->credits()->create(['amount' => 10]);
    $bob->credits()->create(['amount' => 20]);

    expect(Credit::query()->forCreditable($ada)->count())->toBe(1);
});
