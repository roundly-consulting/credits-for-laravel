<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Tests\Fixtures\CustomCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('uses the configured credit model override', function (): void {
    config()->set('credits.model', CustomCredit::class);

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(75);

    expect($user->credits()->first())->toBeInstanceOf(CustomCredit::class)
        ->and($user->creditsBalance())->toBe(75);
});
