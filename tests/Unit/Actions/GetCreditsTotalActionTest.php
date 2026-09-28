<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\GetCreditsTotalAction;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('sums every bucket when no list is given', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100, bucket: 'promotional');
    $user->modifyCredits(40, bucket: 'purchased');
    $user->modifyCredits(5);

    expect(app(GetCreditsTotalAction::class)->execute($user))->toBe(145);
});

it('sums only the listed buckets, de-duplicated, and zero for none', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100, bucket: 'promotional');
    $user->modifyCredits(40, bucket: 'purchased');

    expect(app(GetCreditsTotalAction::class)->execute($user, ['promotional', 'promotional']))->toBe(100)
        ->and(app(GetCreditsTotalAction::class)->execute($user, []))->toBe(0);
});

it('limits the sum to a point in time and to one owner', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $other = User::query()->create(['name' => 'Bob']);
    $old = $user->modifyCredits(100);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->modifyCredits(40);
    $other->modifyCredits(999);

    expect(app(GetCreditsTotalAction::class)->execute($user, at: now()->subHour()))->toBe(100)
        ->and(app(GetCreditsTotalAction::class)->execute($user))->toBe(140);
});
