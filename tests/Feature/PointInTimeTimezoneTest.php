<?php

declare(strict_types=1);

use Carbon\Carbon;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * `$at` is an instant. `created_at` is stored in `app.timezone`, and Laravel binds a date
 * as its wall clock with no conversion, so an `$at` in another timezone used to be compared
 * by its wall clock: 11:30 in Bratislava (09:30 UTC) counted a row written at 10:00 UTC.
 * The fake compared instants, so it answered the opposite way.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));

    $this->before = Carbon::parse('2026-10-06 11:30', 'Europe/Bratislava'); // 09:30 UTC
    $this->after = Carbon::parse('2026-10-06 06:30', 'America/New_York');   // 10:30 UTC
});

it('reads a point-in-time balance by instant, whatever the timezone of $at', function (bool $fake): void {
    if ($fake) {
        Credits::fake();
    }

    $user = User::query()->create(['name' => 'Ada']);
    Credits::for($user)->add(100);

    expect($user->creditsBalance($this->before))->toBe(0)
        ->and($user->totalCreditsBalance($this->before))->toBe(0)
        ->and(Credits::for($user)->buckets(['default'])->balance($this->before))->toBe(0)
        ->and($user->creditsBalance($this->after))->toBe(100)
        ->and($user->totalCreditsBalance($this->after))->toBe(100)
        ->and(Credits::for($user)->buckets(['default'])->balance($this->after))->toBe(100);
})->with(['the real manager' => false, 'the fake' => true]);

it('scopes upTo() by instant, whatever the timezone of $at', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    expect(Credit::query()->upTo($this->before)->count())->toBe(0)
        ->and(Credit::query()->upTo($this->after)->count())->toBe(1);
});
