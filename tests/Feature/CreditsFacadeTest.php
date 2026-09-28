<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\BucketNotDenominatedException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Handles\CreditBuckets;
use RoundlyConsulting\Credits\Handles\CreditsScope;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    config()->set('credits.currencies', ['store_credit' => 'EUR', 'points' => 'PTS']);

    $this->user = User::query()->create(['name' => 'Ada']);
});

it('adds and deducts through a scope, with description and meta', function (): void {
    Event::fake([CreditsModified::class]);

    $added = Credits::for($this->user)->add(100, 'Welcome', ['source' => 'signup']);
    $deducted = Credits::for($this->user)->deduct(30, meta: ['order' => 7]);

    expect($added)->toBeInstanceOf(Credit::class)
        ->and($added->amount)->toBe(100)
        ->and($added->description)->toBe('Welcome')
        ->and($added->meta)->toBe(['source' => 'signup'])
        ->and($deducted->amount)->toBe(-30)
        ->and($deducted->meta)->toBe(['order' => 7])
        ->and(Credits::for($this->user)->balance())->toBe(70);

    Event::assertDispatchedTimes(CreditsModified::class, 2);
});

it('applies a signed change with modify()', function (): void {
    Credits::for($this->user)->modify(50);
    Credits::for($this->user)->modify(-20);

    expect(Credits::for($this->user)->balance())->toBe(30);
});

it('refuses a negative amount to add() or deduct()', function (string $verb): void {
    Credits::for($this->user)->{$verb}(-5);
})->with(['add', 'deduct'])->throws(InvalidArgumentException::class, 'non-negative amount, -5 given');

it('guards deductions against overdraft unless the scope allows it', function (): void {
    Credits::for($this->user)->add(10);

    expect(fn (): Credit => Credits::for($this->user)->deduct(30))->toThrow(InsufficientCreditsException::class);

    Credits::for($this->user)->allowOverdraft()->deduct(30);

    expect(Credits::for($this->user)->balance())->toBe(-20);
});

it('is immutable: narrowing returns a new scope', function (): void {
    $scope = Credits::for($this->user);

    $points = $scope->bucket('points');
    $overdraft = $scope->allowOverdraft();

    $points->add(5);

    expect($points)->toBeInstanceOf(CreditsScope::class)->not->toBe($scope)
        ->and($overdraft)->not->toBe($scope)
        ->and($scope->balance())->toBe(0)
        ->and($points->balance())->toBe(5)
        ->and(fn (): Credit => $scope->deduct(1))->toThrow(InsufficientCreditsException::class)
        ->and($overdraft->allowOverdraft(false))->not->toBe($overdraft);
});

it('isolates buckets and reads has(), total() and a multi-bucket view', function (): void {
    Credits::for($this->user)->bucket('points')->add(100, 'Welcome');
    Credits::for($this->user)->bucket('promo')->add(40);
    Credits::for($this->user)->add(5);

    $view = Credits::for($this->user)->buckets(['points', 'promo', 'points']);

    expect(Credits::for($this->user)->bucket('points')->balance())->toBe(100)
        ->and(Credits::for($this->user)->balance())->toBe(5)
        ->and(Credits::for($this->user)->bucket('points')->has(100))->toBeTrue()
        ->and(Credits::for($this->user)->has(6))->toBeFalse()
        ->and(Credits::for($this->user)->bucket('promo')->total())->toBe(145)
        ->and($view)->toBeInstanceOf(CreditBuckets::class)
        ->and($view->balance())->toBe(140)
        ->and($view->has(140))->toBeTrue()
        ->and($view->has(141))->toBeFalse()
        ->and(Credits::for($this->user)->buckets([])->balance())->toBe(0);
});

it('reads a balance as of a point in time', function (): void {
    $old = Credits::for($this->user)->add(100);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    Credits::for($this->user)->add(40);

    expect(Credits::for($this->user)->balance(now()->subHour()))->toBe(100)
        ->and(Credits::for($this->user)->has(101, now()->subHour()))->toBeFalse()
        ->and(Credits::for($this->user)->total(now()->subHour()))->toBe(100)
        ->and(Credits::for($this->user)->buckets(['default'])->balance(now()->subHour()))->toBe(100);
});

it('sets an exact balance and is a no-op when it already matches', function (): void {
    Credits::for($this->user)->add(80);

    $credit = Credits::for($this->user)->setTo(30, 'Reset', ['by' => 'admin']);

    expect($credit?->amount)->toBe(-50)
        ->and($credit?->description)->toBe('Reset')
        ->and(Credits::for($this->user)->balance())->toBe(30)
        ->and(Credits::for($this->user)->setTo(30))->toBeNull()
        ->and(fn (): ?Credit => Credits::for($this->user)->setTo(-5))->toThrow(InsufficientCreditsException::class)
        ->and(Credits::for($this->user)->allowOverdraft()->setTo(-5)?->amount)->toBe(-35);
});

it('works in money on a denominated bucket', function (): void {
    $store = Credits::for($this->user)->bucket('store_credit');

    $store->addMoney(Money::ofMajor('25.00', 'EUR'), 'Gift card');
    $store->deductMoney(Money::ofMinor(1050, 'EUR'), 'Order #42');
    $store->modifyMoney(Money::ofMinor(-50, 'EUR'));

    expect($store->currency()?->code)->toBe('EUR')
        ->and($store->money()->minor())->toBe('1400')
        ->and($store->balance())->toBe(1400)
        ->and($store->format())->toBe('14.00')
        ->and($store->format(scale: 0))->toBe('14')
        ->and($store->formatMoney('en'))->toBe('€14.00');
});

it('refuses money in another currency or on a plain bucket', function (): void {
    expect(fn (): Credit => Credits::for($this->user)->bucket('store_credit')->addMoney(Money::ofMinor(100, 'USD')))
        ->toThrow(CurrencyMismatch::class)
        ->and(fn (): Money => Credits::for($this->user)->money())
        ->toThrow(BucketNotDenominatedException::class, 'default')
        ->and(fn (): Credit => Credits::for($this->user)->bucket('promo')->addMoney(Money::ofMinor(100, 'EUR')))
        ->toThrow(BucketNotDenominatedException::class, 'promo')
        ->and(fn (): Credit => Credits::for($this->user)->bucket('store_credit')->addMoney(Money::ofMinor(-100, 'EUR')))
        ->toThrow(InvalidArgumentException::class);

    expect($this->user->credits()->count())->toBe(0);
});

it('formats any amount and resolves a bucket currency without an owner', function (): void {
    config()->set('credits.scale', 2);

    expect(Credits::format(1250))->toBe('12.50')
        ->and(Credits::format(1250, scale: 0))->toBe('13')
        ->and(Credits::format(1250, scale: 0, rounding: RoundingMode::HalfTowardsZero))->toBe('12')
        ->and(Credits::format(1250, storedScale: 0))->toBe('1250')
        ->and(Credits::currency('store_credit')?->code)->toBe('EUR')
        ->and(Credits::currency('promo'))->toBeNull()
        ->and(Credits::for($this->user)->format())->toBe('0.00');
});

it('exposes the flat verbs the scopes end in', function (): void {
    Credits::modify($this->user, new CreditChangeData(amount: 60, bucket: 'points'));
    Credits::modify($this->user, new CreditChangeData(amount: 10));

    expect(Credits::balance($this->user, 'points'))->toBe(60)
        ->and(Credits::balance($this->user))->toBe(10)
        ->and(Credits::total($this->user))->toBe(70)
        ->and(Credits::total($this->user, ['points']))->toBe(60)
        ->and(Credits::setTo($this->user, 0, bucket: 'points')?->amount)->toBe(-60)
        ->and(Credits::balance($this->user, 'points'))->toBe(0);
});

it('serves the same api to an injected manager', function (): void {
    $manager = app(CreditsManager::class);

    $manager->for($this->user)->bucket('points')->add(25);

    expect($manager)->toBe(app(CreditsManager::class))
        ->and($manager->for($this->user)->bucket('points')->balance())->toBe(25)
        ->and(Credits::for($this->user)->bucket('points')->balance())->toBe(25);
});

it('keeps the trait and the facade on one ledger', function (): void {
    $this->user->modifyCredits(40, bucket: 'points');
    Credits::for($this->user)->bucket('points')->deduct(15);

    expect($this->user->creditsBalance(bucket: 'points'))->toBe(25)
        ->and(Credits::for($this->user)->bucket('points')->balance())->toBe(25);
});
