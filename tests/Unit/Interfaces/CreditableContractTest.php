<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('is satisfied by the HasCredits trait', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    expect($user)->toBeInstanceOf(Creditable::class);

    // Every contract method resolves and behaves.
    $user->modifyCredits(100);

    expect($user->creditsBalance())->toBe(100)
        ->and($user->hasCredits(100))->toBeTrue()
        ->and($user->credits()->count())->toBe(1)
        ->and($user->setCreditsTo(100))->toBeNull();
});
