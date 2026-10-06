<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Testing\CreditsFake;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    $this->user = User::query()->create(['name' => 'Ada']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('installs itself behind the facade and injected managers', function (): void {
    $fake = Credits::fake();

    expect($fake)->toBeInstanceOf(CreditsFake::class)
        ->and(app(CreditsManager::class))->toBe($fake);
});

it('records changes without writing a row or firing an event', function (): void {
    Event::fake([CreditsModified::class]);
    $fake = Credits::fake();

    $credit = Credits::for($this->user)->bucket('points')->add(100, 'Welcome');

    expect($credit->exists)->toBeFalse()
        ->and($credit->amount)->toBe(100)
        ->and($credit->bucket)->toBe('points')
        ->and(Credit::query()->count())->toBe(0);

    Event::assertNotDispatched(CreditsModified::class);
    $fake->assertAdded($this->user, 100, bucket: 'points');
    $fake->assertAdded($this->user);
});

it('keeps an in-memory balance on top of the real ledger', function (): void {
    $this->user->modifyCredits(50);
    Credits::fake();

    Credits::for($this->user)->add(20);
    Credits::for($this->user)->bucket('points')->add(5);

    expect(Credits::for($this->user)->balance())->toBe(70)
        ->and(Credits::for($this->user)->has(70))->toBeTrue()
        ->and(Credits::for($this->user)->total())->toBe(75)
        ->and(Credits::for($this->user)->buckets(['points'])->balance())->toBe(5)
        ->and(Credits::for($this->user)->buckets([])->balance())->toBe(0)
        ->and(Credits::for($this->user)->balance(now()->subMinute()))->toBe(0)
        ->and(Credit::query()->count())->toBe(1);
});

it('still refuses an overdraft, and does not record it', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->add(10);

    expect(fn (): Credit => Credits::for($this->user)->deduct(30))->toThrow(InsufficientCreditsException::class);

    $fake->assertNothingDeducted();
    Credits::for($this->user)->allowOverdraft()->deduct(30);
    $fake->assertDeducted($this->user, 30);
});

it('asserts a deduction by amount and bucket', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->bucket('points')->add(50);

    Credits::for($this->user)->bucket('points')->deduct(30, meta: ['order' => 1]);

    $fake->assertDeducted($this->user, 30, bucket: 'points');
    $fake->assertDeducted($this->user);
});

it('fails asserting a deduction of another amount', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->allowOverdraft()->deduct(30);

    $fake->assertDeducted($this->user, 31);
})->throws(AssertionFailedError::class, 'amount 31');

it('fails asserting a grant for another owner', function (): void {
    $fake = Credits::fake();
    $other = User::query()->create(['name' => 'Bob']);
    Credits::for($this->user)->add(10);

    $fake->assertAdded($other);
})->throws(AssertionFailedError::class, 'Expected credits to be added');

it('fails asserting a grant in another bucket', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->add(10);

    $fake->assertAdded($this->user, 10, bucket: 'points');
})->throws(AssertionFailedError::class, 'bucket "points"');

it('records a set, even a no-op one, and applies the delta', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->add(40);

    expect(Credits::for($this->user)->setTo(40))->toBeNull()
        ->and(Credits::for($this->user)->setTo(10)?->amount)->toBe(-30)
        ->and(Credits::for($this->user)->balance())->toBe(10);

    $fake->assertSet($this->user, 40);
    $fake->assertSet($this->user, 10, bucket: 'default');
    $fake->assertNothingDeducted();
});

it('refuses a set below the floor on the fake too', function (): void {
    $fake = Credits::fake();

    expect(fn (): ?Credit => Credits::for($this->user)->setTo(-1))->toThrow(InsufficientCreditsException::class);

    $fake->assertNothingSet();
});

it('fails asserting a set that did not happen', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->setTo(5);

    $fake->assertSet($this->user, 6);
})->throws(AssertionFailedError::class, 'Expected a balance to be set');

it('asserts nothing was added, deducted, set or modified', function (): void {
    $fake = Credits::fake();

    $fake->assertNothingAdded();
    $fake->assertNothingDeducted();
    $fake->assertNothingSet();
    $fake->assertNothingModified();
});

it('fails asserting nothing added after a grant', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->add(1);

    $fake->assertNothingAdded();
})->throws(AssertionFailedError::class);

it('fails asserting nothing deducted after a deduction', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->allowOverdraft()->deduct(1);

    $fake->assertNothingDeducted();
})->throws(AssertionFailedError::class);

it('fails asserting nothing set after a set', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->setTo(0);

    $fake->assertNothingSet();
})->throws(AssertionFailedError::class);

it('fails asserting nothing modified after a set', function (): void {
    $fake = Credits::fake();
    Credits::for($this->user)->setTo(0);

    $fake->assertNothingModified();
})->throws(AssertionFailedError::class);

it('records changes made through the HasCredits trait', function (): void {
    $fake = Credits::fake();

    $this->user->modifyCredits(100, bucket: 'points');
    $this->user->modifyCredits(-40, bucket: 'points');
    $this->user->setCreditsTo(5);

    expect($this->user->creditsBalance(bucket: 'points'))->toBe(60)
        ->and($this->user->hasCredits(60, bucket: 'points'))->toBeTrue()
        ->and($this->user->totalCreditsBalance())->toBe(65)
        ->and($this->user->creditsBalanceForBuckets(['points']))->toBe(60)
        ->and(Credit::query()->count())->toBe(0);

    $fake->assertAdded($this->user, 100, bucket: 'points');
    $fake->assertDeducted($this->user, 40, bucket: 'points');
    $fake->assertSet($this->user, 5);
});

it('records money changes made through the trait', function (): void {
    config()->set('credits.currencies', ['store_credit' => 'EUR']);
    $fake = Credits::fake();

    $this->user->modifyCreditsMoney(Money::ofMinor(1500, 'EUR'), bucket: 'store_credit');

    expect($this->user->creditsBalanceMoney('store_credit')->minor())->toBe('1500');
    $fake->assertAdded($this->user, 1500, bucket: 'store_credit');
});

it('fails asserting nothing modified after a trait change', function (): void {
    $fake = Credits::fake();
    $this->user->modifyCredits(1);

    $fake->assertNothingModified();
})->throws(AssertionFailedError::class);

it('records the credits:modify command', function (): void {
    $fake = Credits::fake();
    config()->set('credits.modifiable', [fn (Closure $modify) => $modify($this->user)]);

    Artisan::call('credits:modify', ['--amount' => 15, '--bucket' => 'points']);

    $fake->assertAdded($this->user, 15, bucket: 'points');
    expect(Credit::query()->count())->toBe(0);
});

it('records the flat verbs', function (): void {
    $fake = Credits::fake();

    Credits::modify($this->user, new CreditChangeData(amount: 9));
    Credits::setTo($this->user, 3);

    expect(Credits::balance($this->user))->toBe(3);
    $fake->assertAdded($this->user, 9);
    $fake->assertSet($this->user, 3);
});

it('reads allow_overdraft as an env boolean, like the real guard', function (): void {
    $fake = Credits::fake();

    config()->set('credits.allow_overdraft', 'off');

    expect(fn (): Credit => Credits::for($this->user)->deduct(10))->toThrow(InsufficientCreditsException::class);
    $fake->assertNothingDeducted();

    config()->set('credits.allow_overdraft', '1');

    Credits::for($this->user)->deduct(10);

    expect(Credits::for($this->user)->balance())->toBe(-10);
    $fake->assertDeducted($this->user, 10);
});

/**
 * The real ledger stores `created_at` and binds `$at` at second precision, so a change made
 * later in the same second counts at `$at`. The fake used to stamp and compare microseconds,
 * and answered the opposite way.
 */
it('compares a point in time at second precision, like the real ledger', function (bool $fake): void {
    if ($fake) {
        Credits::fake();
    }

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00.250000', 'UTC'));
    $at = now();

    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00.750000', 'UTC'));
    Credits::for($this->user)->add(10);

    expect($this->user->creditsBalance($at))->toBe(10)
        ->and($this->user->totalCreditsBalance($at))->toBe(10)
        ->and($this->user->creditsBalance(Carbon::parse('2026-10-06 09:59:59.999999', 'UTC')))->toBe(0);
})->with(['the real manager' => false, 'the fake' => true]);

/**
 * Runs `$seed` on the real ledger and then installs the fake, or installs the fake first and
 * seeds that — the overflow cases below must hold over both.
 */
function fakeSeeded(bool $onTheFake, Closure $seed): CreditsFake
{
    $fake = $onTheFake ? Credits::fake() : null;
    $seed();

    return $fake ?? Credits::fake();
}

/**
 * The real ledger refuses a change, a setTo() delta or a total outside int64 with money's
 * AmountOverflow before anything is written (LedgerOverflowTest). The fake used to record the
 * change anyway, and its next balance() failed with a TypeError on the float the sum became.
 */
it('refuses a change whose resulting balance overflows int64 on the fake, and records nothing', function (bool $onTheFake): void {
    config()->set('credits.currencies', ['crypto' => 'ETH']);
    $fake = fakeSeeded($onTheFake, fn (): Credit => $this->user->modifyCreditsMoney(Money::ofMajor('9', 'ETH'), bucket: 'crypto'));

    expect(fn (): mixed => $this->user->modifyCreditsMoney(Money::ofMajor('1', 'ETH'), bucket: 'crypto'))
        ->toThrow(AmountOverflow::class, 'The credits balance of bucket [crypto] would be [10000000000000000000] after this change, which does not fit a 64-bit integer; nothing was written.')
        ->and(fn (): mixed => $this->user->modifyCredits(PHP_INT_MAX, bucket: 'crypto'))
        ->toThrow(AmountOverflow::class, '[18223372036854775807]')
        ->and($this->user->creditsBalance(bucket: 'crypto'))->toBe(9_000_000_000_000_000_000)
        ->and(fn () => $fake->assertAdded($this->user, 1_000_000_000_000_000_000))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertAdded($this->user, PHP_INT_MAX))->toThrow(AssertionFailedError::class)
        ->and(Credit::query()->count())->toBe($onTheFake ? 0 : 1);
})->with(['seeded on the real ledger' => false, 'seeded on the fake' => true]);

it('refuses an overdraft past the bottom of int64 on the fake, and records nothing', function (bool $onTheFake): void {
    $fake = fakeSeeded($onTheFake, fn (): Credit => $this->user->modifyCredits(PHP_INT_MIN + 5, allowOverdraft: true));

    expect(fn (): mixed => $this->user->modifyCredits(-10, allowOverdraft: true))
        ->toThrow(AmountOverflow::class, 'The credits balance of bucket [default] would be [-9223372036854775813] after this change, which does not fit a 64-bit integer; nothing was written.')
        ->and(fn (): mixed => Credits::for($this->user)->allowOverdraft()->deduct(10))
        ->toThrow(AmountOverflow::class, '[-9223372036854775813]')
        ->and($this->user->creditsBalance())->toBe(PHP_INT_MIN + 5)
        ->and(fn () => $fake->assertDeducted($this->user, 10))->toThrow(AssertionFailedError::class);

    $fake->assertNothingAdded();
})->with(['seeded on the real ledger' => false, 'seeded on the fake' => true]);

it('refuses a setTo whose delta overflows int64 on the fake, and records nothing', function (bool $onTheFake): void {
    $fake = fakeSeeded($onTheFake, fn (): Credit => $this->user->modifyCredits(-10, allowOverdraft: true));

    expect(fn (): mixed => $this->user->setCreditsTo(PHP_INT_MAX, allowOverdraft: true))
        ->toThrow(AmountOverflow::class, 'Setting the credits balance of bucket [default] to [9223372036854775807] needs a change of [9223372036854775817], which does not fit a 64-bit integer; nothing was written.')
        ->and($this->user->creditsBalance())->toBe(-10);

    $fake->assertNothingSet();
    $fake->assertNothingAdded();
})->with(['seeded on the real ledger' => false, 'seeded on the fake' => true]);

it('refuses a total across buckets that overflows int64 on the fake', function (int $each, string $total, bool $bothOnTheFake): void {
    $fake = fakeSeeded($bothOnTheFake, fn (): Credit => $this->user->modifyCredits($each, allowOverdraft: true, bucket: 'a'));
    $this->user->modifyCredits($each, allowOverdraft: true, bucket: 'b');

    expect(fn (): mixed => $this->user->totalCreditsBalance())
        ->toThrow(AmountOverflow::class, "The credits total across every bucket is [{$total}], which does not fit a 64-bit integer.")
        ->and(fn (): mixed => Credits::total($this->user))->toThrow(AmountOverflow::class, "[{$total}]")
        ->and(fn (): mixed => Credits::for($this->user)->buckets(['a', 'b', 'a'])->balance())
        ->toThrow(AmountOverflow::class, "The credits total across buckets [a, b] is [{$total}], which does not fit a 64-bit integer.")
        ->and(Credits::for($this->user)->buckets(['a'])->balance())->toBe($each)
        ->and(Credits::total($this->user, ['b']))->toBe($each);

    // Each bucket fits, so both changes are recorded; only their sum is refused.
    $each > 0 ? $fake->assertAdded($this->user, $each, bucket: 'b') : $fake->assertDeducted($this->user, -$each, bucket: 'b');
})->with([
    'past the top' => [intdiv(PHP_INT_MAX, 2) + 1, '9223372036854775808'],
    'past the bottom' => [intdiv(PHP_INT_MIN, 2) - 1, '-9223372036854775810'],
])->with(['one bucket on the real ledger' => false, 'both on the fake' => true]);

/**
 * Every change keeps a bucket inside int64, but a point-in-time read of a ledger written out of
 * order (a test that moved the clock back) can sum to more: that is AmountOverflow too, never a
 * TypeError.
 */
it('throws AmountOverflow for a fake balance that does not fit int64', function (): void {
    Credits::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:01', 'UTC'));
    Credits::for($this->user)->add(PHP_INT_MAX);
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:02', 'UTC'));
    Credits::for($this->user)->deduct(10);
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    Credits::for($this->user)->add(10);

    expect($this->user->creditsBalance())->toBe(PHP_INT_MAX)
        ->and(fn (): int => $this->user->creditsBalance(Carbon::parse('2026-10-06 10:00:01', 'UTC')))
        ->toThrow(AmountOverflow::class, 'The credits balance of bucket [default] is [9223372036854775817], which does not fit a 64-bit integer.');
});

/**
 * PHP_INT_MIN is the one int whose magnitude is not an int — abs() makes it a float. The real
 * ledger writes that deduction (the bucket ends at PHP_INT_MIN); the fake recorded it through
 * abs() and threw a TypeError instead of applying it.
 */
it('applies a deduction of exactly PHP_INT_MIN like the real ledger', function (bool $onTheFake): void {
    if ($onTheFake) {
        Credits::fake();
    }

    $credit = Credits::modify($this->user, new CreditChangeData(amount: PHP_INT_MIN, allowOverdraft: true));

    expect($credit->amount)->toBe(PHP_INT_MIN)
        ->and(Credits::balance($this->user))->toBe(PHP_INT_MIN)
        ->and($this->user->creditsBalance())->toBe(PHP_INT_MIN)
        ->and(Credit::query()->count())->toBe($onTheFake ? 0 : 1);
})->with(['on the real ledger' => false, 'on the fake' => true]);

it('records a deduction of exactly PHP_INT_MIN on the fake as a deduction', function (): void {
    $fake = Credits::fake();

    Credits::modify($this->user, new CreditChangeData(amount: PHP_INT_MIN, allowOverdraft: true, bucket: 'points'));

    $fake->assertDeducted($this->user);
    $fake->assertDeducted($this->user, bucket: 'points');
    $fake->assertNothingAdded();
    $fake->assertNothingSet();

    expect(fn () => $fake->assertDeducted($this->user, PHP_INT_MAX))->toThrow(AssertionFailedError::class, 'amount '.PHP_INT_MAX)
        ->and(fn () => $fake->assertDeducted($this->user, bucket: 'default'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingDeducted())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingModified())->toThrow(AssertionFailedError::class);
});

it('matches a deduction by its positive amount only, as before', function (): void {
    $fake = Credits::fake();

    Credits::for($this->user)->allowOverdraft()->deduct(30);
    Credits::for($this->user)->add(30);

    $fake->assertDeducted($this->user, 30);
    $fake->assertAdded($this->user, 30);

    expect(fn () => $fake->assertDeducted($this->user, -30))->toThrow(AssertionFailedError::class, 'amount -30')
        ->and(fn () => $fake->assertAdded($this->user, -30))->toThrow(AssertionFailedError::class, 'amount -30');
});
