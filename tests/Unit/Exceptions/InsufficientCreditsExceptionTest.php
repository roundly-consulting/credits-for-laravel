<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\CreditsException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
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

/**
 * The guard refuses a debit that would cross `credits.minimum_balance`, so the message names
 * what can actually be spent above that floor — not the raw balance, which used to read
 * "only 100 are available" for a refused debit of 60.
 */
it('shows what can be spent above the floor, on the real manager and the fake', function (bool $fake, int $floor, int $deduct, string $message): void {
    config()->set('credits.minimum_balance', $floor);

    if ($fake) {
        Credits::fake();
    }

    $user = User::query()->create(['name' => 'Ada']);
    Credits::for($user)->add(100);

    expect(fn (): mixed => Credits::for($user)->deduct($deduct))
        ->toThrow(function (InsufficientCreditsException $e) use ($floor, $deduct, $message): void {
            expect($e->getMessage())->toBe($message)
                ->and($e->requested)->toBe(-$deduct)
                ->and($e->available)->toBe(100)
                ->and($e->minimum)->toBe($floor);
        });
})->with([
    'the real manager' => false,
    'the fake' => true,
])->with([
    'a floor of 50' => [50, 60, 'Insufficient credits: tried to deduct 60 but only 50 can be spent above the minimum balance of 50.'],
    'a floor of 30' => [30, 80, 'Insufficient credits: tried to deduct 80 but only 70 can be spent above the minimum balance of 30.'],
    'no floor keeps the wording' => [0, 130, 'Insufficient credits: tried to deduct 130 but only 100 are available.'],
]);

it('shows the floor in slovak too', function (): void {
    app()->setLocale('sk');

    $exception = new InsufficientCreditsException(User::query()->create(['name' => 'Ada']), requested: -60, available: 100, minimum: 50);

    expect($exception->getMessage())
        ->toBe('Nedostatočný zostatok kreditov: pokus o odpočítanie 60, nad minimálnym zostatkom 50 je však možné minúť iba 50.');
});
