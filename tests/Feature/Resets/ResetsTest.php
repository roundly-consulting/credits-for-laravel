<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-09 12:00:00');

    $this->user = User::query()->create(['name' => 'Ada']);
    $this->user->modifyCredits(70, bucket: 'free');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('names the period an instant falls in', function (ResetPeriod $period, string $at, string $key): void {
    expect($period->key(CarbonImmutable::parse($at)))->toBe($key);
})->with([
    [ResetPeriod::Daily, '2026-11-09 23:59:59', '2026-11-09'],
    [ResetPeriod::Weekly, '2026-11-09 00:00:00', '2026-W46'],
    // ISO weeks: 31 Dec 2024 is in week 1 of 2025.
    [ResetPeriod::Weekly, '2024-12-31 12:00:00', '2025-W01'],
    [ResetPeriod::Monthly, '2026-11-30 23:59:59', '2026-11'],
    [ResetPeriod::Yearly, '2026-01-01 00:00:00', '2026'],
]);

it('resets a bucket once per period', function (): void {
    Event::fake([CreditsModified::class]);

    $first = Credits::for($this->user)->bucket('free')->reset(ResetPeriod::Monthly, to: 100);
    $this->user->modifyCredits(-30, bucket: 'free');
    $second = Credits::for($this->user)->bucket('free')->reset(ResetPeriod::Monthly, to: 100);

    expect($first->reset)->toBeTrue()
        ->and($first->period)->toBe('2026-11')
        ->and($first->credit?->amount)->toBe(30)
        ->and($first->credit?->bucket)->toBe('free')
        ->and($first->credit?->meta)->toBe(['reset_period' => '2026-11'])
        ->and($second->reset)->toBeFalse()
        ->and($second->period)->toBe('2026-11')
        ->and($second->credit)->toBeNull()
        ->and($this->user->creditsBalance(bucket: 'free'))->toBe(70);

    $marker = DB::table('credit_resets')->first();

    expect(DB::table('credit_resets')->count())->toBe(1)
        ->and($marker->bucket)->toBe('free')
        ->and($marker->period)->toBe('2026-11')
        ->and((int) $marker->reset_to)->toBe(100)
        ->and($marker->credit_id)->toEqual($first->credit?->id);

    Event::assertDispatchedTimes(CreditsModified::class, 2);
});

it('resets again in the next period', function (): void {
    $this->user->resetCredits(ResetPeriod::Monthly, bucket: 'free');
    $this->user->modifyCredits(40, bucket: 'free');

    CarbonImmutable::setTestNow('2026-12-01 00:00:00');
    $result = $this->user->resetCredits(ResetPeriod::Monthly, bucket: 'free');

    expect($result->reset)->toBeTrue()
        ->and($result->period)->toBe('2026-12')
        ->and($this->user->creditsBalance(bucket: 'free'))->toBe(0);
});

it('records a period even when the balance already matches', function (): void {
    $result = Credits::reset($this->user, '2026-11', to: 70, bucket: 'free');

    expect($result->reset)->toBeTrue()
        ->and($result->credit)->toBeNull()
        ->and(DB::table('credit_resets')->value('credit_id'))->toBeNull()
        ->and(Credits::reset($this->user, '2026-11', to: 0, bucket: 'free')->reset)->toBeFalse();
});

it('keeps buckets and owners apart', function (): void {
    $other = User::query()->create(['name' => 'Bob']);

    Credits::reset($this->user, ResetPeriod::Daily, bucket: 'free');

    expect(Credits::reset($this->user, ResetPeriod::Daily, bucket: 'paid')->reset)->toBeTrue()
        ->and(Credits::reset($other, ResetPeriod::Daily, bucket: 'free')->reset)->toBeTrue()
        ->and(Credits::reset($this->user, ResetPeriod::Daily)->reset)->toBeTrue()
        ->and(DB::table('credit_resets')->count())->toBe(4);
});

it('takes an explicit period key, trimmed', function (): void {
    expect(Credits::reset($this->user, ' billing-cycle-7 ', bucket: 'free')->period)->toBe('billing-cycle-7');
});

it('refuses a blank or oversized period key', function (string $key): void {
    Credits::reset($this->user, $key, bucket: 'free');
})->with(['', '   ', str_repeat('x', 65)])->throws(InvalidArgumentException::class, 'A reset period key must be 1 to 64 characters');

it('refuses to reset below the held credits, and leaves no marker', function (): void {
    config()->set('credits.holds.enabled', true);
    Credits::for($this->user)->bucket('free')->hold(50);

    expect(fn () => Credits::for($this->user)->bucket('free')->reset(ResetPeriod::Monthly))
        ->toThrow(InsufficientCreditsException::class);

    expect(DB::table('credit_resets')->count())->toBe(0)
        ->and($this->user->creditsBalance(bucket: 'free'))->toBe(70);

    $result = Credits::for($this->user)->bucket('free')->allowOverdraft()->reset(ResetPeriod::Monthly);

    expect($result->reset)->toBeTrue()
        ->and($this->user->creditsBalance(bucket: 'free'))->toBe(0)
        ->and($this->user->availableCredits('free'))->toBe(-50);
});

it('exposes the reset through dependency injection', function (): void {
    $result = app(CreditsManager::class)->reset($this->user, ResetPeriod::Yearly, to: 5, bucket: 'free', description: 'New year', meta: ['by' => 'test']);

    expect($result->period)->toBe('2026')
        ->and($result->credit?->description)->toBe('New year')
        ->and($result->credit?->meta)->toBe(['by' => 'test'])
        ->and($this->user->creditsBalance(bucket: 'free'))->toBe(5);
});
