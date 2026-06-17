<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

function balanceAction(): GetCreditsBalanceAction
{
    return app(GetCreditsBalanceAction::class);
}

it('sums the balance across several named buckets', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 40, bucket: 'purchased');
    $user->modifyCredits(amount: 7, bucket: 'gift');

    expect($user->creditsBalanceForBuckets(['promotional', 'purchased']))->toBe(140);
});

it('excludes untouched buckets from a multi-bucket sum', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 40, bucket: 'purchased');
    $user->modifyCredits(amount: 999, bucket: 'gift');

    expect($user->creditsBalanceForBuckets(['promotional', 'purchased']))->toBe(140);
});

it('de-duplicates bucket names when summing', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100, bucket: 'promotional');

    expect($user->creditsBalanceForBuckets(['promotional', 'promotional']))->toBe(100);
});

it('returns zero for an empty bucket list', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100, bucket: 'promotional');

    expect($user->creditsBalanceForBuckets([]))->toBe(0);
});

it('respects the point-in-time filter when summing buckets', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $old = $user->modifyCredits(amount: 100, bucket: 'promotional');
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->modifyCredits(amount: 40, bucket: 'purchased');

    expect($user->creditsBalanceForBuckets(['promotional', 'purchased'], now()->subHour()))->toBe(100);
});

it('sums across every bucket for a total balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 40, bucket: 'purchased');
    $user->modifyCredits(amount: 5);

    expect($user->totalCreditsBalance())->toBe(145);
});

it('respects the point-in-time filter on a total balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $old = $user->modifyCredits(amount: 100, bucket: 'promotional');
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->modifyCredits(amount: 40, bucket: 'purchased');

    expect($user->totalCreditsBalance(now()->subHour()))->toBe(100);
});

it('isolates total balances between creditables', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);
    $ada->modifyCredits(amount: 100, bucket: 'promotional');
    $bob->modifyCredits(amount: 5, bucket: 'promotional');

    expect($ada->totalCreditsBalance())->toBe(100);
});

it('returns zero total for a creditable with no credits', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    expect($user->totalCreditsBalance())->toBe(0);
});

afterEach(function (): void {
    Carbon::setTestNow();
});
