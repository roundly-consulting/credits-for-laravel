<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Money;

/**
 * The ledger is signed 64-bit. A change whose resulting balance (or a `setTo()` whose delta)
 * does not fit used to be written anyway: the row committed, then `CreditsModified(int
 * $balance)` threw a TypeError on the float, and the bucket stayed broken. A total across
 * buckets past int64 silently saturated (pgsql) or threw a QueryException (sqlite). All of
 * them are money's AmountOverflow now, raised before anything is written.
 */
beforeEach(function (): void {
    config()->set('credits.currencies', ['crypto' => 'ETH']);

    $this->user = User::query()->create(['name' => 'Ada']);
});

it('refuses a change whose resulting balance overflows int64 and writes nothing', function (): void {
    $this->user->modifyCreditsMoney(Money::ofMajor('9', 'ETH'), bucket: 'crypto');
    Event::fake([CreditsModified::class]);

    expect(fn (): mixed => $this->user->modifyCreditsMoney(Money::ofMajor('1', 'ETH'), bucket: 'crypto'))
        ->toThrow(AmountOverflow::class, '[10000000000000000000]')
        ->and(fn (): mixed => $this->user->modifyCredits(PHP_INT_MAX, bucket: 'crypto'))
        ->toThrow(AmountOverflow::class)
        ->and($this->user->credits()->count())->toBe(1)
        ->and($this->user->creditsBalance(bucket: 'crypto'))->toBe(9_000_000_000_000_000_000);

    Event::assertNotDispatched(CreditsModified::class);
});

it('refuses an overdraft past the bottom of int64 and writes nothing', function (): void {
    $this->user->modifyCredits(PHP_INT_MIN + 5, allowOverdraft: true);
    Event::fake([CreditsModified::class]);

    expect(fn (): mixed => $this->user->modifyCredits(-10, allowOverdraft: true))
        ->toThrow(AmountOverflow::class, '[-9223372036854775813]')
        ->and($this->user->credits()->count())->toBe(1)
        ->and($this->user->creditsBalance())->toBe(PHP_INT_MIN + 5);

    Event::assertNotDispatched(CreditsModified::class);
});

it('refuses a setTo whose delta overflows int64 and writes nothing', function (): void {
    $this->user->modifyCredits(-10, allowOverdraft: true);
    Event::fake([CreditsModified::class]);

    expect(fn (): mixed => $this->user->setCreditsTo(PHP_INT_MAX, allowOverdraft: true))
        ->toThrow(AmountOverflow::class, '[9223372036854775817]')
        ->and($this->user->credits()->count())->toBe(1)
        ->and($this->user->creditsBalance())->toBe(-10);

    Event::assertNotDispatched(CreditsModified::class);
});

it('refuses a total across buckets that overflows int64', function (int $each): void {
    $this->user->modifyCredits($each, allowOverdraft: true, bucket: 'a');
    $this->user->modifyCredits($each, allowOverdraft: true, bucket: 'b');

    expect(fn (): mixed => $this->user->totalCreditsBalance())->toThrow(AmountOverflow::class)
        ->and(fn (): mixed => Credits::total($this->user))->toThrow(AmountOverflow::class)
        ->and(fn (): mixed => Credits::for($this->user)->buckets(['a', 'b'])->balance())->toThrow(AmountOverflow::class)
        ->and(Credits::for($this->user)->buckets(['a'])->balance())->toBe($each)
        ->and(Credits::total($this->user, ['b']))->toBe($each);
})->with([
    'past the top' => [intdiv(PHP_INT_MAX, 2) + 1],
    'past the bottom' => [intdiv(PHP_INT_MIN, 2) - 1],
]);
