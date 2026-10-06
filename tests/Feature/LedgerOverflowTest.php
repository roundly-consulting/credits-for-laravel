<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\Credit;
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

afterEach(function (): void {
    Carbon::setTestNow();
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

/**
 * Every change keeps the bucket inside int64, in the order the rows are written. A point-in-time
 * read selects by `created_at`, so on rows written out of that order (a backdated row, a test that
 * moved the clock back) it can add up to more: sqlite threw a QueryException ("integer
 * overflow"), postgres a `numeric` the int cast capped at PHP_INT_MAX.
 */
it('refuses a point-in-time balance that overflows int64', function (string $ledger): void {
    if ($ledger === 'the fake') {
        Credits::fake();
    }

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:01', 'UTC'));
    $this->user->modifyCredits(PHP_INT_MAX);
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:02', 'UTC'));
    $this->user->modifyCredits(-10);
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    $this->user->modifyCredits(10);

    if ($ledger === 'rows on the real ledger, read through the fake') {
        Credits::fake();
    }

    $at = Carbon::parse('2026-10-06 10:00:01', 'UTC');
    // sqlite refuses the sum itself, so only postgres (and the fake's own rows) can name it.
    $message = $ledger !== 'the fake' && $this->user->credits()->getConnection()->getDriverName() === 'sqlite'
        ? 'The credits balance of bucket [default] does not fit a 64-bit integer.'
        : 'The credits balance of bucket [default] is [9223372036854775817], which does not fit a 64-bit integer.';

    expect(fn (): int => $this->user->creditsBalance($at))->toThrow(AmountOverflow::class, $message)
        ->and(fn (): int => Credits::balance($this->user, at: $at))->toThrow(AmountOverflow::class, $message)
        ->and(fn (): int => Credits::for($this->user)->balance($at))->toThrow(AmountOverflow::class, $message)
        ->and($this->user->creditsBalance(Carbon::parse('2026-10-06 10:00:02', 'UTC')))->toBe(PHP_INT_MAX)
        ->and($this->user->creditsBalance())->toBe(PHP_INT_MAX);
})->with(['the real ledger', 'rows on the real ledger, read through the fake', 'the fake']);

/**
 * The balance every change decides on is read under a row lock, so it cannot be an aggregate
 * (postgres refuses `sum()` next to FOR UPDATE): the locked amounts are added up in PHP, in the
 * order the engine returns them. That is not the order the changes kept inside int64, and a host
 * may delete a row between two big ones — a plain `+` passed int64 on the way, became a float, and
 * the int cast read PHP_INT_MIN: a debit was "insufficient", and an allowed overdraft or a
 * `setTo()` was decided against that number. The locked sum is exact now.
 */
it('decides a change on the exact locked balance when the rows pass int64 on the way', function (Closure $change, int $amount, int $balance): void {
    // Written directly, in an order no change keeps inside int64; they add up to PHP_INT_MAX.
    foreach ([PHP_INT_MAX, 10, -10] as $row) {
        Credit::factory()->for($this->user, 'creditable')->create(['amount' => $row]);
    }

    // Precondition: the engine returns them in that order, where a PHP running sum leaves int64.
    expect($this->user->credits()->pluck('amount')->all())->toBe([PHP_INT_MAX, 10, -10])
        ->and($this->user->credits()->pluck('amount')->sum())->toBeFloat();

    Event::fake([CreditsModified::class]);

    expect($change($this->user)->amount)->toBe($amount)
        ->and(app(GetCreditsBalanceAction::class)->execute($this->user, lockForUpdate: true))->toBe($balance);

    Event::assertDispatched(CreditsModified::class, static fn (CreditsModified $event): bool => $event->balance === $balance);
})->with([
    'a guarded debit' => [fn (User $user): Credit => $user->modifyCredits(-1), -1, PHP_INT_MAX - 1],
    'a debit with an overdraft allowed' => [fn (User $user): Credit => $user->modifyCredits(-1, allowOverdraft: true), -1, PHP_INT_MAX - 1],
    'setTo' => [fn (User $user): ?Credit => $user->setCreditsTo(5), 5 - PHP_INT_MAX, 5],
]);

it('refuses a change when the locked balance does not fit int64 and writes nothing', function (Closure $change): void {
    $this->user->modifyCredits(PHP_INT_MAX);
    $deleted = $this->user->modifyCredits(-10);
    $this->user->modifyCredits(10);
    // A host removes a ledger row between two big ones: PHP_INT_MAX + 10 is left behind.
    $deleted->delete();
    Event::fake([CreditsModified::class]);

    $message = 'The credits balance of bucket [default] is [9223372036854775817], which does not fit a 64-bit integer.';

    expect(fn (): mixed => $change($this->user))->toThrow(AmountOverflow::class, $message)
        ->and(fn (): int => app(GetCreditsBalanceAction::class)->execute($this->user, lockForUpdate: true))
        ->toThrow(AmountOverflow::class, $message)
        ->and($this->user->credits()->withTrashed()->count())->toBe(3);

    Event::assertNotDispatched(CreditsModified::class);
})->with([
    'a guarded debit' => [fn (User $user): Credit => $user->modifyCredits(-1)],
    'a debit with an overdraft allowed' => [fn (User $user): Credit => $user->modifyCredits(-1, allowOverdraft: true)],
    'a grant' => [fn (User $user): Credit => $user->modifyCredits(5)],
    'setTo' => [fn (User $user): ?Credit => $user->setCreditsTo(0, allowOverdraft: true)],
    'through the facade' => [fn (User $user): Credit => Credits::for($user)->deduct(1)],
]);
