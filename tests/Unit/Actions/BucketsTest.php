<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('keeps balances isolated between named buckets', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 40, bucket: 'purchased');

    expect($user->creditsBalance(bucket: 'promotional'))->toBe(100)
        ->and($user->creditsBalance(bucket: 'purchased'))->toBe(40);
});

it('uses the default bucket when none is given and does not sum across buckets', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 25);

    // A bucket-less read returns only the default bucket's balance.
    expect($user->creditsBalance())->toBe(25)
        ->and($user->creditsBalance(bucket: 'default'))->toBe(25)
        ->and($user->creditsBalance(bucket: 'promotional'))->toBe(100);
});

it('persists the resolved default bucket on rows written without a bucket', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->modifyCredits(amount: 10);

    expect($credit->bucket)->toBe('default');
});

it('honours a custom configured default bucket for bucket-less calls', function (): void {
    config()->set('credits.default_bucket', 'wallet');
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->modifyCredits(amount: 10);

    expect($credit->bucket)->toBe('wallet')
        ->and($user->creditsBalance())->toBe(10);
});

it('enforces the overdraft guard per bucket', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 50, bucket: 'promotional');

    // Plenty of credit in the promotional bucket, but the purchased bucket is empty,
    // so a deduction there must still be rejected.
    expect(fn () => $user->modifyCredits(amount: -10, bucket: 'purchased'))
        ->toThrow(InsufficientCreditsException::class);

    // The promotional bucket can be deducted independently.
    $user->modifyCredits(amount: -10, bucket: 'promotional');

    expect($user->creditsBalance(bucket: 'promotional'))->toBe(40)
        ->and($user->creditsBalance(bucket: 'purchased'))->toBe(0);
});

it('sets a single bucket to an exact amount without touching others', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->setCreditsTo(amount: 30, bucket: 'purchased');

    expect($user->creditsBalance(bucket: 'purchased'))->toBe(30)
        ->and($user->creditsBalance(bucket: 'promotional'))->toBe(100);
});

it('scopes a query to a single bucket', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 100, bucket: 'promotional');
    $user->modifyCredits(amount: 40, bucket: 'purchased');

    expect(Credit::query()->bucket('promotional')->count())->toBe(1)
        ->and((int) Credit::query()->bucket('promotional')->sum('amount'))->toBe(100);
});

it('reports per-bucket availability through hasCredits', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(amount: 100, bucket: 'promotional');

    expect($user->hasCredits(50, bucket: 'promotional'))->toBeTrue()
        ->and($user->hasCredits(50, bucket: 'purchased'))->toBeFalse();
});
