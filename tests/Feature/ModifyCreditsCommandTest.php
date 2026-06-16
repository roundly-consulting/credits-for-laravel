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

it('applies a negative amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($user),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -40]);

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(60);
});

it('applies credits to multiple entities across resolvers', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($ada),
        fn (Closure $modify) => $modify($bob),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 10]);

    expect($exitCode)->toBe(0)
        ->and($ada->creditsBalance())->toBe(10)
        ->and($bob->creditsBalance())->toBe(10);
});

it('rejects a non-integer amount', function (): void {
    config()->set('credits.modifiable', []);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 'abc']);

    expect($exitCode)->toBe(1);
});

it('warns and skips a non-creditable resolved entity', function (): void {
    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify(new stdClass),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 10]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('not a Creditable');
});

it('supports overdraft via the flag', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(20);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($user),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -30, '--allow-overdraft' => true]);

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(-10);
});

it('succeeds with no configured resolvers', function (): void {
    config()->set('credits.modifiable', []);

    $exitCode = Artisan::call('credits:modify');

    expect($exitCode)->toBe(0);
});
