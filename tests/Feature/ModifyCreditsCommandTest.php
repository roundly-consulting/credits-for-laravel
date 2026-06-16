<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('applies credits to entities resolved from config', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    config()->set('credits.modifiable', [
        function (Closure $modify) use ($user): void {
            $modify($user);
        },
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 75, '--description' => 'batch top-up']);

    $credit = $user->credits()->sole();

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(75)
        ->and($credit->description)->toBe('batch top-up')
        ->and($credit->meta)->toBe(['info' => 'Credits modified by credits:modify command.']);
});

it('succeeds with no configured resolvers', function (): void {
    config()->set('credits.modifiable', []);

    $exitCode = Artisan::call('credits:modify');

    expect($exitCode)->toBe(0);
});
