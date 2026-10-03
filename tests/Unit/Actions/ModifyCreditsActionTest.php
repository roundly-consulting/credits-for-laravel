<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('records a row with the right attributes and returns the credit', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(
        amount: 50,
        description: 'bonus',
        meta: ['source' => 'promo'],
    ));

    expect($credit)->toBeInstanceOf(Credit::class)
        ->and($credit->amount)->toBe(50)
        ->and($credit->description)->toBe('bonus')
        ->and($credit->meta)->toBe(['source' => 'promo']);
});

it('dispatches the CreditsModified event', function (): void {
    Event::fake();
    $user = User::query()->create(['name' => 'Ada']);

    app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: 10));

    Event::assertDispatched(CreditsModified::class);
});

it('rejects a deduction beyond the available balance by default', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    expect(fn () => app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -30)))
        ->toThrow(InsufficientCreditsException::class);

    expect($user->creditsBalance())->toBe(20);
});

it('allows a deduction down to exactly the floor', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -20));

    expect($user->creditsBalance())->toBe(0);
});

it('allows overdraft per call', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -30, allowOverdraft: true));

    expect($user->creditsBalance())->toBe(-10);
});

it('allows overdraft when enabled globally', function (): void {
    config()->set('credits.allow_overdraft', true);
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -30));

    expect($user->creditsBalance())->toBe(-10);
});

it('carries the requested and available amounts on the exception', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->credits()->create(['amount' => 20]);

    try {
        app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -30));
    } catch (InsufficientCreditsException $exception) {
        expect($exception->requested)->toBe(-30)
            ->and($exception->available)->toBe(20);

        return;
    }

    Assert::fail('Expected InsufficientCreditsException.');
});

// Regression: `(bool) config(...)` read the env string "off"/"no"/"false" as true, turning
// overdraft ON globally; `CREDITS_ALLOW_OVERDRAFT=1` overdrew while `about` said BLOCKED.
it('reads allow_overdraft as an env boolean', function (mixed $value, bool $overdraws): void {
    config()->set('credits.allow_overdraft', $value);
    $user = User::query()->create(['name' => 'Ada']);

    $deduct = fn (): Credit => app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -50));

    if ($overdraws) {
        $deduct();

        expect($user->creditsBalance())->toBe(-50);

        return;
    }

    expect($deduct)->toThrow(InsufficientCreditsException::class)
        ->and($user->creditsBalance())->toBe(0);
})->with([
    'true' => [true, true],
    '"1"' => ['1', true],
    '"true"' => ['true', true],
    '"on"' => ['on', true],
    '"yes"' => ['yes', true],
    'false' => [false, false],
    '"0"' => ['0', false],
    '"false"' => ['false', false],
    '"off"' => ['off', false],
    '"no"' => ['no', false],
    '""' => ['', false],
    'null' => [null, false],
]);

it('refuses to deduct on an unparseable allow_overdraft instead of reading it as off', function (): void {
    config()->set('credits.allow_overdraft', 'maybe');
    $user = User::query()->create(['name' => 'Ada']);

    expect(fn (): Credit => app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -50)))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [credits.allow_overdraft] must be a boolean (true/false, 1/0, on/off or yes/no), [maybe] given.',
    )->and($user->creditsBalance())->toBe(0);
});
