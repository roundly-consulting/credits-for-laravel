<?php

declare(strict_types=1);

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

it('modifies credits with a description and meta', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(30, 'signup bonus', ['source' => 'promo']);

    $credit = $user->credits()->sole();

    expect($credit->amount)->toBe(30)
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

    $user->setCreditsTo(40);

    expect($user->creditsBalance())->toBe(40)
        ->and($user->credits()->count())->toBe(1);
});
