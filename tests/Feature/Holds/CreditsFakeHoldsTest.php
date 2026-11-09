<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Events\CreditsHeld;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('credits.holds.enabled', true);

    $this->user = User::query()->create(['name' => 'Ada']);
    $this->user->modifyCredits(100);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('holds in memory, without a row or an event', function (): void {
    Event::fake([CreditsHeld::class]);
    $fake = Credits::fake();

    $hold = $this->user->holdCredits(30, description: 'Order');

    expect($hold->exists)->toBeFalse()
        ->and($hold->status)->toBe(CreditHoldStatus::Held)
        ->and($hold->description)->toBe('Order')
        ->and(Credits::held($this->user))->toBe(30)
        ->and(Credits::available($this->user))->toBe(70)
        ->and(Credits::balance($this->user))->toBe(100)
        ->and(CreditHold::query()->count())->toBe(0);

    Event::assertNotDispatched(CreditsHeld::class);
    $fake->assertHeld($this->user, 30, 'default');
    $fake->assertHeld($this->user);
});

it('applies the real rules to a hold', function (): void {
    Credits::fake();
    Credits::for($this->user)->hold(70);

    expect(fn (): CreditHold => Credits::for($this->user)->hold(31))->toThrow(InsufficientCreditsException::class)
        ->and(fn (): Credit => Credits::for($this->user)->deduct(31))->toThrow(InsufficientCreditsException::class)
        ->and(fn (): CreditHold => Credits::for($this->user)->hold(0))->toThrow(InvalidArgumentException::class);

    config()->set('credits.holds.enabled', false);

    expect(fn (): CreditHold => Credits::for($this->user)->hold(1))->toThrow(CreditHoldsDisabledException::class);
});

it('captures a fake hold into the in-memory ledger', function (): void {
    Event::fake([CreditHoldCaptured::class]);
    $fake = Credits::fake();
    $hold = Credits::for($this->user)->hold(30);

    $credit = Credits::for($this->user)->capture($hold, 10);

    expect($credit->exists)->toBeFalse()
        ->and($credit->amount)->toBe(-10)
        ->and($hold->status)->toBe(CreditHoldStatus::Captured)
        ->and($hold->captured_amount)->toBe(10)
        ->and(Credits::balance($this->user))->toBe(90)
        ->and(Credits::available($this->user))->toBe(90)
        ->and(fn (): Credit => Credits::capture($hold))->toThrow(CreditHoldNotActiveException::class);

    Event::assertNotDispatched(CreditHoldCaptured::class);
    $fake->assertCaptured($this->user, 10, 'default');
    $fake->assertCaptured($this->user);
});

it('captures and releases a hold saved before the fake, leaving its row alone', function (): void {
    $saved = Credits::for($this->user)->hold(30);
    $other = Credits::for($this->user)->hold(20);
    $fake = Credits::fake();

    expect(Credits::held($this->user))->toBe(50);

    Credits::capture(CreditHold::query()->findOrFail($saved->id));
    $other->release();

    expect(Credits::held($this->user))->toBe(0)
        ->and(Credits::balance($this->user))->toBe(70)
        ->and($saved->fresh()?->status)->toBe(CreditHoldStatus::Held)
        ->and($other->status)->toBe(CreditHoldStatus::Released)
        ->and($other->fresh()?->status)->toBe(CreditHoldStatus::Held)
        ->and(fn (): CreditHold => Credits::release($saved))->toThrow(CreditHoldNotActiveException::class, 'captured');

    $fake->assertCaptured($this->user, 30);
    $fake->assertReleased($this->user, 20, 'default');
});

it('releases a fake hold', function (): void {
    $fake = Credits::fake();
    $hold = Credits::for($this->user)->hold(30);

    expect(Credits::for($this->user)->release($hold))->toBe($hold)
        ->and($hold->status)->toBe(CreditHoldStatus::Released)
        ->and(Credits::available($this->user))->toBe(100);

    $fake->assertReleased($this->user);
    $fake->assertNothingCaptured();
});

it('expires its own stale holds in memory', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    $fake = Credits::fake();
    $stale = Credits::for($this->user)->hold(30, CarbonImmutable::parse('2026-11-09 12:05:00'));
    $later = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 13:00:00'));

    expect($fake->expireHolds(CarbonImmutable::parse('2026-11-09 12:30:00')))->toBe(1)
        ->and($stale->status)->toBe(CreditHoldStatus::Expired)
        ->and($later->status)->toBe(CreditHoldStatus::Held);

    config()->set('credits.holds.enabled', false);

    expect($fake->expireHolds())->toBe(0);
});

it('resets once per period in memory, and honours a period already reset in the database', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    Credits::reset($this->user, ResetPeriod::Monthly, bucket: 'promo');
    $fake = Credits::fake();

    $first = Credits::for($this->user)->reset(ResetPeriod::Monthly, to: 40);
    $again = Credits::for($this->user)->reset(ResetPeriod::Monthly, to: 40);
    $saved = Credits::for($this->user)->bucket('promo')->reset(ResetPeriod::Monthly, to: 40);
    $noop = Credits::for($this->user)->reset('other', to: 40);

    expect($first->reset)->toBeTrue()
        ->and($first->credit?->amount)->toBe(-60)
        ->and($first->credit?->meta)->toBe(['reset_period' => '2026-11'])
        ->and($again->reset)->toBeFalse()
        ->and($saved->reset)->toBeFalse()
        ->and($noop->reset)->toBeTrue()
        ->and($noop->credit)->toBeNull()
        ->and(Credits::balance($this->user))->toBe(40)
        ->and(DB::table('credit_resets')->count())->toBe(1);

    $fake->assertReset($this->user, 'default', '2026-11');
    $fake->assertReset($this->user);
    $fake->assertNothingSet();
});

it('keeps the nothing-assertions passing on an untouched fake', function (): void {
    $fake = Credits::fake();

    $fake->assertNothingHeld();
    $fake->assertNothingCaptured();
    $fake->assertNothingReleased();
    $fake->assertNothingReset();
    $fake->assertNothingModified();
});

it('fails an assertion that did not happen', function (Closure $act, Closure $assert, string $message): void {
    $fake = Credits::fake();
    $act($this->user);

    expect(fn () => $assert($fake, $this->user))->toThrow(AssertionFailedError::class, $message);
})->with([
    'held, nothing held' => [fn (User $u) => null, fn ($f, User $u) => $f->assertHeld($u), 'Expected credits to be held'],
    'held, other amount' => [fn (User $u) => $u->holdCredits(5), fn ($f, User $u) => $f->assertHeld($u, 6), 'amount 6'],
    'nothing held' => [fn (User $u) => $u->holdCredits(5), fn ($f) => $f->assertNothingHeld(), 'Expected no credits to be held'],
    'captured, none' => [fn (User $u) => $u->holdCredits(5), fn ($f, User $u) => $f->assertCaptured($u), 'Expected a hold to be captured'],
    'nothing captured' => [fn (User $u) => $u->holdCredits(5)->capture(), fn ($f) => $f->assertNothingCaptured(), 'Expected no hold to be captured'],
    'released, none' => [fn (User $u) => $u->holdCredits(5), fn ($f, User $u) => $f->assertReleased($u), 'Expected a hold to be released'],
    'nothing released' => [fn (User $u) => $u->holdCredits(5)->release(), fn ($f) => $f->assertNothingReleased(), 'Expected no hold to be released'],
    'reset, none' => [fn (User $u) => null, fn ($f, User $u) => $f->assertReset($u), 'Expected a reset'],
    'reset, other bucket' => [fn (User $u) => $u->resetCredits('p1'), fn ($f, User $u) => $f->assertReset($u, 'promo'), 'bucket "promo"'],
    'reset, other period' => [fn (User $u) => $u->resetCredits('p1'), fn ($f, User $u) => $f->assertReset($u, period: 'p2'), 'period "p2"'],
    'nothing reset' => [fn (User $u) => $u->resetCredits('p1'), fn ($f) => $f->assertNothingReset(), 'Expected no reset'],
    'nothing modified after a hold' => [fn (User $u) => $u->holdCredits(5), fn ($f) => $f->assertNothingModified(), 'Expected no credits to be modified'],
]);
