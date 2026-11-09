<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldExpired;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Jobs\ExpireCreditHolds;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('credits.holds.enabled', true);
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');

    $this->user = User::query()->create(['name' => 'Ada']);
    $this->user->modifyCredits(100);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('marks only the holds past their expiry as expired', function (): void {
    Event::fake([CreditHoldExpired::class]);

    $stale = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));
    $alsoStale = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:10:00'));
    $later = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 13:00:00'));
    $forever = Credits::for($this->user)->hold(10);
    $captured = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:01:00'));
    $captured->capture();

    CarbonImmutable::setTestNow('2026-11-09 12:10:00');

    expect(Credits::expireHolds())->toBe(2)
        ->and($stale->fresh()?->status)->toBe(CreditHoldStatus::Expired)
        ->and($stale->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-11-09 12:10:00')
        ->and($alsoStale->fresh()?->status)->toBe(CreditHoldStatus::Expired)
        ->and($later->fresh()?->status)->toBe(CreditHoldStatus::Held)
        ->and($forever->fresh()?->status)->toBe(CreditHoldStatus::Held)
        ->and($captured->fresh()?->status)->toBe(CreditHoldStatus::Captured)
        ->and(Credits::held($this->user))->toBe(20)
        // A second sweep has nothing left to do.
        ->and(Credits::expireHolds())->toBe(0);

    Event::assertDispatchedTimes(CreditHoldExpired::class, 2);
    Event::assertDispatched(CreditHoldExpired::class, fn (CreditHoldExpired $event): bool => $event->hold->is($stale)
        && $event->hold->status === CreditHoldStatus::Expired);
});

it('sweeps as of a given instant', function (): void {
    $hold = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));

    expect(Credits::expireHolds(CarbonImmutable::parse('2026-11-09 12:04:59')))->toBe(0)
        ->and(Credits::expireHolds(CarbonImmutable::parse('2026-11-09 13:05:00', 'Europe/Bratislava')))->toBe(1)
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Expired);
});

it('expires a hold whose owner is gone', function (): void {
    $hold = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));
    $this->user->delete();

    expect(Credits::expireHolds(CarbonImmutable::parse('2026-11-09 13:00:00')))->toBe(1)
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Expired);
});

it('sweeps in chunks', function (): void {
    $this->user->modifyCredits(1000);

    foreach (range(1, 105) as $i) {
        CreditHold::factory()->create([
            'creditable_type' => $this->user->getMorphClass(),
            'creditable_id' => $this->user->id,
            'amount' => 1,
            'expires_at' => CarbonImmutable::parse('2026-11-09 12:05:00'),
        ]);
    }

    expect(Credits::expireHolds(CarbonImmutable::parse('2026-11-09 12:05:00')))->toBe(105)
        ->and(CreditHold::query()->where('status', 'expired')->count())->toBe(105);
});

it('runs from the command', function (): void {
    Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));
    CarbonImmutable::setTestNow('2026-11-09 12:06:00');

    $this->artisan('credits:expire-holds')
        ->expectsOutputToContain('Expired 1 hold.')
        ->assertSuccessful();

    $this->artisan('credits:expire-holds', ['--connection' => config('database.default')])
        ->expectsOutputToContain('Expired 0 holds.')
        ->assertSuccessful();
});

it('succeeds doing nothing from the command while holds are off', function (): void {
    config()->set('credits.holds.enabled', false);

    $this->artisan('credits:expire-holds')
        ->expectsOutputToContain('Credit holds are disabled; nothing to expire.')
        ->assertSuccessful();
});

it('runs as a queued job', function (): void {
    $hold = Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));
    CarbonImmutable::setTestNow('2026-11-09 12:06:00');

    Bus::dispatchSync(new ExpireCreditHolds);

    expect($hold->fresh()?->status)->toBe(CreditHoldStatus::Expired)
        ->and((new ExpireCreditHolds('ledger'))->connectionName)->toBe('ledger');
});

it('goes through the facade, so a fake sees the job', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->hold(10, CarbonImmutable::parse('2026-11-09 12:05:00'));
    CarbonImmutable::setTestNow('2026-11-09 12:06:00');

    expect($fake->expireHolds())->toBe(1);

    Bus::dispatchSync(new ExpireCreditHolds);

    expect(CreditHold::query()->count())->toBe(0);
});
