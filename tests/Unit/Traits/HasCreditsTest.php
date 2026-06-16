<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('exposes a credits relationship', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 25]);

    expect($user->credits()->count())->toBe(1)
        ->and($user->credits()->first())->toBeInstanceOf(Credit::class);
});

it('reports the current credits balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);
    $user->modifyCredits(-40);

    expect($user->creditsBalance())->toBe(60);
});

it('modifies credits with a description and meta and returns the credit', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->modifyCredits(30, 'signup bonus', ['source' => 'promo']);

    expect($credit)->toBeInstanceOf(Credit::class)
        ->and($credit->amount)->toBe(30)
        ->and($credit->description)->toBe('signup bonus')
        ->and($credit->meta)->toBe(['source' => 'promo']);
});

it('sets credits to an exact higher amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(20);

    $user->setCreditsTo(50);

    expect($user->creditsBalance())->toBe(50);
});

it('sets credits to an exact lower amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(80);

    $user->setCreditsTo(30);

    expect($user->creditsBalance())->toBe(30);
});

it('does nothing when setting credits to the current balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(40);

    expect($user->setCreditsTo(40))->toBeNull()
        ->and($user->creditsBalance())->toBe(40)
        ->and($user->credits()->count())->toBe(1);
});

it('reports whether enough credits are available', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(50);

    expect($user->hasCredits())->toBeTrue()
        ->and($user->hasCredits(50))->toBeTrue()
        ->and($user->hasCredits(51))->toBeFalse();
});

it('honours a point in time for hasCredits', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $old = $user->credits()->create(['amount' => 100]);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $user->credits()->create(['amount' => -100]);

    expect($user->hasCredits(100, now()->subHour()))->toBeTrue()
        ->and($user->hasCredits(1))->toBeFalse();
});

it('rejects an overdrawing deduction through the trait', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(20);

    expect(fn () => $user->modifyCredits(-30))
        ->toThrow(InsufficientCreditsException::class);
});

it('permits a per-call overdraft through the trait', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(20);

    $user->modifyCredits(-30, allowOverdraft: true);

    expect($user->creditsBalance())->toBe(-10);
});
