<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Events\CreditHoldReleased;
use RoundlyConsulting\Credits\Events\CreditsHeld;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Exceptions\ForeignCreditHoldException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\LockRecordingCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('credits.holds.enabled', true);

    $this->user = User::query()->create(['name' => 'Ada']);
    $this->user->modifyCredits(100);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    LockRecordingCredit::resetLocks();
});

describe('switched off', function (): void {
    beforeEach(fn () => config()->set('credits.holds.enabled', false));

    it('refuses a hold and names the switch', function (): void {
        expect(fn (): CreditHold => Credits::for($this->user)->hold(10))
            ->toThrow(CreditHoldsDisabledException::class, 'credits.holds.enabled');

        expect(CreditHold::query()->count())->toBe(0);
    });

    it('reports nothing held and the whole balance available, without reading the holds', function (): void {
        CreditHold::factory()->create(['creditable_type' => $this->user->getMorphClass(), 'creditable_id' => $this->user->id, 'amount' => 40]);

        expect(Credits::held($this->user))->toBe(0)
            ->and(Credits::available($this->user))->toBe(100)
            ->and(Credits::expireHolds())->toBe(0);

        // A debit ignores holds while they are off — today's behaviour, unchanged.
        $this->user->modifyCredits(-100);

        expect($this->user->creditsBalance())->toBe(0);
    });
});

it('reserves credits: the balance stays, the available balance drops', function (): void {
    Event::fake([CreditsHeld::class, CreditsModified::class]);

    $hold = Credits::for($this->user)->hold(30, description: 'Order #1', meta: ['order' => 1]);

    expect($hold->exists)->toBeTrue()
        ->and($hold->status)->toBe(CreditHoldStatus::Held)
        ->and($hold->amount)->toBe(30)
        ->and($hold->bucket)->toBe('default')
        ->and($hold->description)->toBe('Order #1')
        ->and($hold->meta)->toBe(['order' => 1])
        ->and($hold->expires_at)->toBeNull()
        ->and($hold->creditable->is($this->user))->toBeTrue()
        ->and(Credits::balance($this->user))->toBe(100)
        ->and(Credits::held($this->user))->toBe(30)
        ->and(Credits::available($this->user))->toBe(70)
        ->and(Credits::for($this->user)->hasAvailable(70))->toBeTrue()
        ->and(Credits::for($this->user)->hasAvailable(71))->toBeFalse()
        ->and(Credit::query()->count())->toBe(1);

    Event::assertDispatched(CreditsHeld::class, fn (CreditsHeld $event): bool => $event->hold->is($hold)
        && $event->creditable->is($this->user)
        && $event->available === 70);
    Event::assertNotDispatched(CreditsModified::class);
});

it('stops a debit from spending held credits', function (): void {
    Credits::for($this->user)->hold(60);

    expect(fn (): Credit => $this->user->modifyCredits(-50))
        ->toThrow(InsufficientCreditsException::class, 'only 40 are available');

    $this->user->modifyCredits(-40);

    expect($this->user->creditsBalance())->toBe(60)
        ->and($this->user->availableCredits())->toBe(0);
});

it('lets an overdraft debit spend held credits', function (): void {
    Credits::for($this->user)->hold(60);

    $this->user->modifyCredits(-100, allowOverdraft: true);

    expect($this->user->creditsBalance())->toBe(0)
        ->and($this->user->availableCredits())->toBe(-60);
});

it('refuses a setTo that would lower the balance below the held credits', function (): void {
    Credits::for($this->user)->hold(60);

    expect(fn (): ?Credit => $this->user->setCreditsTo(50))->toThrow(InsufficientCreditsException::class);

    expect($this->user->setCreditsTo(60)?->amount)->toBe(-40);
});

it('refuses a hold beyond the available balance', function (): void {
    Credits::for($this->user)->hold(70);

    expect(fn (): CreditHold => Credits::for($this->user)->hold(31))
        ->toThrow(InsufficientCreditsException::class, 'only 30 are available');

    expect(CreditHold::query()->count())->toBe(1);
});

it('holds down to the minimum balance, and past it when an overdraft is allowed', function (): void {
    config()->set('credits.minimum_balance', -50);

    Credits::for($this->user)->hold(150);

    expect(fn (): CreditHold => Credits::for($this->user)->hold(1))->toThrow(InsufficientCreditsException::class);

    $hold = Credits::for($this->user)->allowOverdraft()->hold(25);
    config()->set('credits.allow_overdraft', true);
    Credits::for($this->user)->hold(25);

    expect($hold->exists)->toBeTrue()
        ->and(Credits::available($this->user))->toBe(-100);
});

it('keeps holds in their bucket', function (): void {
    $this->user->modifyCredits(20, bucket: 'promo');

    $hold = Credits::for($this->user)->bucket('promo')->hold(15);

    expect($hold->bucket)->toBe('promo')
        ->and(Credits::held($this->user, 'promo'))->toBe(15)
        ->and(Credits::held($this->user))->toBe(0)
        ->and(Credits::available($this->user, 'promo'))->toBe(5)
        ->and(Credits::available($this->user))->toBe(100)
        ->and($this->user->heldCredits('promo'))->toBe(15)
        ->and($this->user->hasAvailableCredits(5, 'promo'))->toBeTrue()
        ->and($this->user->hasAvailableCredits(6, 'promo'))->toBeFalse();
});

it('refuses a hold of nothing, or less', function (int $amount): void {
    Credits::for($this->user)->hold($amount);
})->with([0, -5])->throws(InvalidArgumentException::class, 'A hold takes a positive amount');

it('refuses an expiry that has already passed', function (): void {
    Credits::for($this->user)->hold(10, now()->subSecond());
})->throws(InvalidArgumentException::class, 'A hold must expire in the future');

it('gives a hold the configured lifetime when it names none', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    config()->set('credits.holds.expire_after_minutes', 15);

    $hold = Credits::for($this->user)->hold(10);
    $own = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-10 08:00:00'));

    expect($hold->fresh()?->expires_at?->toDateTimeString())->toBe('2026-11-09 12:15:00')
        ->and($own->fresh()?->expires_at?->toDateTimeString())->toBe('2026-11-10 08:00:00');
});

it('stores an expiry given in another timezone as the same instant', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');

    $hold = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 14:30:00', 'Europe/Bratislava'));

    expect($hold->fresh()?->expires_at?->toDateTimeString())->toBe('2026-11-09 13:30:00');
});

it('stops counting a hold the moment it expires, before any sweep', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    $hold = Credits::for($this->user)->hold(40, CarbonImmutable::parse('2026-11-09 12:10:00'));

    expect(Credits::available($this->user))->toBe(60);

    CarbonImmutable::setTestNow('2026-11-09 12:10:00');

    expect(Credits::available($this->user))->toBe(100)
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Held)
        ->and($hold->fresh()?->isActive())->toBeFalse();
});

it('captures a hold in full as one ledger debit', function (): void {
    Event::fake([CreditHoldCaptured::class, CreditsModified::class]);
    $hold = Credits::for($this->user)->hold(30, description: 'Order #1', meta: ['order' => 1]);

    $credit = Credits::capture($hold);

    expect($credit->exists)->toBeTrue()
        ->and($credit->amount)->toBe(-30)
        ->and($credit->description)->toBe('Order #1')
        ->and($credit->meta)->toBe(['order' => 1])
        ->and($hold->status)->toBe(CreditHoldStatus::Captured)
        ->and($hold->captured_amount)->toBe(30)
        ->and($hold->credit_id)->toEqual($credit->id)
        ->and($hold->resolved_at)->not->toBeNull()
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Captured)
        ->and(Credits::balance($this->user))->toBe(70)
        ->and(Credits::held($this->user))->toBe(0)
        ->and(Credits::available($this->user))->toBe(70);

    Event::assertDispatched(CreditHoldCaptured::class, fn (CreditHoldCaptured $event): bool => $event->hold === $hold
        && $event->credit->is($credit)
        && $event->creditable->is($this->user)
        && $event->released === 0);
    Event::assertDispatched(CreditsModified::class, fn (CreditsModified $event): bool => $event->balance === 70);
});

it('captures part of a hold and releases the rest', function (): void {
    Event::fake([CreditHoldCaptured::class]);
    $hold = Credits::for($this->user)->hold(30);

    $credit = $hold->capture(12, 'Shipped 12', ['partial' => true]);

    expect($credit->amount)->toBe(-12)
        ->and($credit->description)->toBe('Shipped 12')
        ->and($credit->meta)->toBe(['partial' => true])
        ->and($hold->captured_amount)->toBe(12)
        ->and(Credits::balance($this->user))->toBe(88)
        ->and(Credits::available($this->user))->toBe(88);

    Event::assertDispatched(CreditHoldCaptured::class, fn (CreditHoldCaptured $event): bool => $event->released === 18);
});

it('refuses a capture outside 1..the held amount', function (int $amount): void {
    $hold = Credits::for($this->user)->hold(30);

    expect(fn (): Credit => Credits::capture($hold, $amount))->toThrow(InvalidArgumentException::class, 'A capture takes 1 to 30');

    expect($hold->fresh()?->status)->toBe(CreditHoldStatus::Held);
})->with([0, -1, 31]);

it('captures a hold even when the minimum balance rose since it was taken', function (): void {
    $hold = Credits::for($this->user)->hold(100);
    config()->set('credits.minimum_balance', 50);

    expect(Credits::capture($hold)->amount)->toBe(-100)
        ->and(Credits::balance($this->user))->toBe(0);
});

it('captures a hold at most once', function (): void {
    $hold = Credits::for($this->user)->hold(30);
    $stale = CreditHold::query()->findOrFail($hold->id);

    Credits::capture($hold);

    expect(fn (): Credit => Credits::capture($stale))
        ->toThrow(CreditHoldNotActiveException::class, 'it is captured');

    expect(Credits::balance($this->user))->toBe(70)
        ->and(Credit::query()->count())->toBe(2)
        ->and($stale->status)->toBe(CreditHoldStatus::Held);
});

it('refuses to capture a hold past its expiry, swept or not', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    $hold = Credits::for($this->user)->hold(30, CarbonImmutable::parse('2026-11-09 12:05:00'));
    CarbonImmutable::setTestNow('2026-11-09 12:05:00');

    try {
        Credits::capture($hold);
        $this->fail('captured an expired hold');
    } catch (CreditHoldNotActiveException $exception) {
        expect($exception->status)->toBe('expired')
            ->and($exception->hold->is($hold))->toBeTrue()
            ->and($exception->getMessage())->toContain('it is expired');
    }

    expect(Credits::balance($this->user))->toBe(100);
});

it('refuses to capture or release a deleted hold', function (string $verb): void {
    $hold = Credits::for($this->user)->hold(30);
    $hold->delete();

    expect(fn (): mixed => Credits::{$verb}($hold))->toThrow(CreditHoldNotActiveException::class, 'it is deleted');
})->with(['capture', 'release']);

it('refuses to capture a hold whose owner is gone', function (): void {
    $hold = Credits::for($this->user)->hold(30);
    $this->user->delete();

    Credits::capture($hold->fresh() ?? $hold);
})->throws(InvalidArgumentException::class, 'is gone or not Creditable');

it('releases a hold without touching the ledger', function (): void {
    Event::fake([CreditHoldReleased::class, CreditsModified::class]);
    $hold = Credits::for($this->user)->hold(30);

    $released = $hold->release();

    expect($released)->toBe($hold)
        ->and($hold->status)->toBe(CreditHoldStatus::Released)
        ->and($hold->resolved_at)->not->toBeNull()
        ->and(Credits::held($this->user))->toBe(0)
        ->and(Credits::available($this->user))->toBe(100)
        ->and(Credit::query()->count())->toBe(1);

    Event::assertDispatched(CreditHoldReleased::class, fn (CreditHoldReleased $event): bool => $event->hold === $hold);
    Event::assertNotDispatched(CreditsModified::class);
});

it('refuses to resolve a hold twice', function (string $first, string $second, string $status): void {
    $hold = Credits::for($this->user)->hold(30);
    $stale = CreditHold::query()->findOrFail($hold->id);

    Credits::{$first}($hold);

    expect(fn (): mixed => Credits::{$second}($stale))->toThrow(CreditHoldNotActiveException::class, "it is {$status}");
})->with([
    'release after release' => ['release', 'release', 'released'],
    'capture after release' => ['release', 'capture', 'released'],
    'release after capture' => ['capture', 'release', 'captured'],
]);

it('refuses a hold of another owner through a scope', function (string $verb): void {
    $other = User::query()->create(['name' => 'Bob']);
    $other->modifyCredits(50);
    $hold = Credits::for($other)->hold(10);

    try {
        Credits::for($this->user)->{$verb}($hold);
        $this->fail('a scope resolved a foreign hold');
    } catch (ForeignCreditHoldException $exception) {
        expect($exception->hold)->toBe($hold)
            ->and($exception->getMessage())->toContain('belongs to another owner');
    }

    expect($hold->fresh()?->status)->toBe(CreditHoldStatus::Held);
})->with(['capture', 'release']);

it('resolves its own hold through a scope', function (): void {
    $first = Credits::for($this->user)->hold(10);
    $second = $this->user->holdCredits(20, bucket: 'default');

    expect(Credits::for($this->user)->capture($first, 4)->amount)->toBe(-4)
        ->and(Credits::for($this->user)->release($second)->status)->toBe(CreditHoldStatus::Released)
        ->and(Credits::available($this->user))->toBe(96);
});

it('reads the ledger and the holds under a lock when it decides a hold', function (): void {
    config()->set('credits.model', LockRecordingCredit::class);
    LockRecordingCredit::resetLocks();

    Credits::for($this->user)->hold(10);

    expect(LockRecordingCredit::$locks)->toBe([1]);
});

it('exposes the hold API through dependency injection', function (): void {
    $credits = app(CreditsManager::class);

    $hold = $credits->hold($this->user, new CreditHoldData(amount: 25, bucket: 'default'));

    expect($credits->held($this->user))->toBe(25)
        ->and($credits->available($this->user))->toBe(75)
        ->and($credits->capture($hold)->amount)->toBe(-25);
});

it('lists the active and stale holds through its scopes', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');
    $active = Credits::for($this->user)->hold(10);
    $stale = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:01:00'));
    Credits::for($this->user)->hold(10)->release();
    CarbonImmutable::setTestNow('2026-11-09 12:02:00');

    expect(CreditHold::query()->forCreditable($this->user)->active()->pluck('id')->all())->toBe([$active->id])
        ->and(CreditHold::query()->stale()->pluck('id')->all())->toBe([$stale->id]);
});
