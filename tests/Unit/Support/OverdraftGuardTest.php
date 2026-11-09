<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Support\OverdraftGuard;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('guards only a debit that is not allowed to overdraw', function (): void {
    expect(OverdraftGuard::applies(-1, false))->toBeTrue()
        ->and(OverdraftGuard::applies(0, false))->toBeFalse()
        ->and(OverdraftGuard::applies(5, false))->toBeFalse()
        ->and(OverdraftGuard::applies(-1, true))->toBeFalse();

    config()->set('credits.allow_overdraft', true);

    expect(OverdraftGuard::applies(-1, false))->toBeFalse();
});

it('refuses a debit below the floor, exactly at the bottom of int64 too', function (int $spendable, int $amount): void {
    OverdraftGuard::check(new User, $spendable, $amount, false);
})->with([
    'one below zero' => [10, -11],
    'past int64' => [PHP_INT_MIN + 5, -10],
])->throws(InsufficientCreditsException::class);

it('lets a debit down to the floor through', function (): void {
    OverdraftGuard::check(new User, 10, -10, false);
    OverdraftGuard::check(new User, 10, -11, true);

    expect(true)->toBeTrue();
});
