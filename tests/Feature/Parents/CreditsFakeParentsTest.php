<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\Budget;

/*
 * Credits::fake() walks credit parents the way the ledger does, in memory.
 */

beforeEach(function (): void {
    config()->set('credits.parents.enabled', true);
    config()->set('credits.holds.enabled', true);

    $this->company = Budget::under(null, 'Acme', 100);
    $this->team = Budget::under($this->company, 'Research', 1_000);
    $this->fake = Credits::fake();
});

it('caps and debits every level of a chain', function (): void {
    $this->team->modifyCredits(-30);

    expect(Credits::balance($this->company))->toBe(70)
        ->and(Credits::balance($this->team))->toBe(970)
        ->and(Credits::availableWithin($this->team)->available)->toBe(70)
        ->and(Credits::availableWithin($this->team)->limitedBy->is($this->company))->toBeTrue()
        ->and(fn () => $this->team->modifyCredits(-71))->toThrow(InsufficientCreditsException::class, 'budget of');

    $this->fake->assertDeducted($this->company, 30);
    $this->fake->assertDeducted($this->team, 30);
});

it('lowers every level with setTo and keeps a grant with its owner', function (): void {
    $this->team->setCreditsTo(990);
    $this->team->modifyCredits(5);

    expect(Credits::balance($this->company))->toBe(90)
        ->and(Credits::balance($this->team))->toBe(995);
});

it('holds, captures and releases every level', function (): void {
    $hold = Credits::for($this->team)->hold(40);

    expect(Credits::held($this->company))->toBe(40)
        ->and(Credits::availableWithin($this->team)->available)->toBe(60);

    $this->fake->assertHeld($this->company, 40);

    Credits::capture($hold, 50, allowOverdraft: true);

    expect(Credits::balance($this->company))->toBe(50)
        ->and(Credits::held($this->company))->toBe(0)
        ->and($hold->status)->toBe(CreditHoldStatus::Captured);

    $this->fake->assertCaptured($this->company, 50);

    $second = Credits::for($this->team)->hold(10);
    Credits::release($second);

    expect(Credits::held($this->company))->toBe(0);

    $this->fake->assertReleased($this->company, 10);
});

it('refuses a hold the parent cannot cover, naming it', function (): void {
    $refusal = null;

    try {
        Credits::for($this->team)->hold(101);
    } catch (InsufficientCreditsException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->creditable->is($this->company))->toBeTrue()
        ->and($refusal?->blockedByParent())->toBeTrue();

    $this->fake->assertNothingHeld();
});

it('refuses to resolve an ancestor level on its own', function (): void {
    Credits::for($this->team)->hold(10);

    $linked = (new ReflectionProperty($this->fake, 'holds'))->getValue($this->fake)[1]['hold'];

    expect($linked)->toBeInstanceOf(CreditHold::class)
        ->and(fn () => Credits::capture($linked))->toThrow(LinkedCreditHoldException::class);
});

it('resolves every saved level of a hold made before the fake', function (): void {
    // Saved by the real ledger, before this test's fake was installed.
    $hold = (new CreditsManager(app()))->for($this->team)->hold(25);
    $fake = Credits::fake();

    $fake->release($hold);

    $fake->assertReleased($this->team, 25);
    $fake->assertReleased($this->company, 25);

    // The rows are left as they were: the fake writes nothing.
    expect(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Held]);
});
