<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\CreditsException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('carries the creditable, requested, and available amounts', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $exception = new InsufficientCreditsException(
        creditable: $user,
        requested: -50,
        available: 30,
    );

    expect($exception->creditable->is($user))->toBeTrue()
        ->and($exception->requested)->toBe(-50)
        ->and($exception->available)->toBe(30)
        ->and($exception)->toBeInstanceOf(CreditsException::class)
        ->and($exception->getMessage())->toContain('50')
        ->and($exception->getMessage())->toContain('30');
});
