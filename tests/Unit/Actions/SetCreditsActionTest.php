<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('raises the balance to an exact amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    $credit = app(SetCreditsAction::class)->execute($user, 50);

    expect($credit)->toBeInstanceOf(Credit::class)
        ->and($user->creditsBalance())->toBe(50);
});

it('lowers the balance to an exact amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 80]);

    app(SetCreditsAction::class)->execute($user, 30);

    expect($user->creditsBalance())->toBe(30);
});

it('returns null and records nothing on a no-op', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 40]);

    $result = app(SetCreditsAction::class)->execute($user, 40);

    expect($result)->toBeNull()
        ->and($user->credits()->count())->toBe(1);
});
