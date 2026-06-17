<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('formats an arbitrary amount through the trait', function (): void {
    config()->set('credits.scale', 2);
    $user = User::query()->create(['name' => 'Ada']);

    expect($user->displayCredits(123450))->toBe('1234.50')
        ->and($user->displayCredits(1250, scale: 0, rounding: PHP_ROUND_HALF_DOWN))->toBe('12');
});

it('formats a single bucket balance through the trait', function (): void {
    config()->set('credits.scale', 2);
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 123450, bucket: 'wallet');

    expect($user->displayCreditsBalance(bucket: 'wallet'))->toBe('1234.50');
});

it('composes a formatted total across all buckets', function (): void {
    config()->set('credits.scale', 2);
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(amount: 100000, bucket: 'promotional');
    $user->modifyCredits(amount: 23450, bucket: 'purchased');

    expect($user->displayCredits($user->totalCreditsBalance()))->toBe('1234.50');
});

afterEach(function (): void {
    config()->set('credits.scale', 0);
    config()->set('credits.rounding', PHP_ROUND_HALF_UP);
});
