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
