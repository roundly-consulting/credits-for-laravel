<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\DataTransferObjects\CreditsAvailability;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Events\CreditHoldExpired;
use RoundlyConsulting\Credits\Events\CreditHoldReleased;
use RoundlyConsulting\Credits\Events\CreditsHeld;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\Budget;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/*
 * Nested budgets: a company, a team inside it, a person inside that. Every debit and hold of
 * an inner budget must also fit, and is written on, every budget around it.
 */

beforeEach(function (): void {
    config()->set('credits.parents.enabled', true);
    config()->set('credits.holds.enabled', true);

    $this->company = Budget::under(null, 'Acme', 100);
    $this->team = Budget::under($this->company, 'Research', 1_000);
    $this->person = Budget::under($this->team, 'Ada', 1_000);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Credits::resolveParentsUsing(null);
});

/** Each budget's balance and held credits, top first. */
function levels(Budget ...$budgets): array
{
    return array_map(static fn (Budget $budget): array => [Credits::balance($budget), Credits::held($budget)], $budgets);
}

describe('switched off', function (): void {
    beforeEach(fn () => config()->set('credits.parents.enabled', false));

    it('lets every owner stand alone, whatever creditParent() says', function (): void {
        $this->person->modifyCredits(-500);
        $hold = Credits::for($this->person)->hold(200);

        expect(levels($this->company, $this->team, $this->person))->toBe([[100, 0], [1_000, 0], [500, 200]])
            ->and($hold->isLinked())->toBeFalse()
            ->and(CreditHold::query()->count())->toBe(1)
            ->and(Credits::availableWithin($this->person)->available)->toBe(300)
            ->and(Credits::availableWithin($this->person)->levels)->toBe(1);
    });
});

it('treats an owner without parents exactly as before', function (): void {
    $user = User::query()->create(['name' => 'Solo']);
    $user->modifyCredits(50);
    $user->modifyCredits(-20);
    $hold = Credits::for($user)->hold(10);
    Credits::capture($hold);

    expect($user->creditsBalance())->toBe(20)
        ->and($hold->isLinked())->toBeFalse()
        ->and($hold->fresh()?->chain_depth)->toBe(0)
        ->and(CreditHold::query()->count())->toBe(1)
        ->and(Credits::availableWithin($user)->limitedBy->is($user))->toBeTrue();
});

it('caps a child by its parent even when the child has more', function (): void {
    $within = Credits::availableWithin($this->person);

    expect($within)->toBeInstanceOf(CreditsAvailability::class)
        ->and($within->available)->toBe(100)
        ->and($within->limitedBy->is($this->company))->toBeTrue()
        ->and($within->limitedByParent($this->person))->toBeTrue()
        ->and($within->levels)->toBe(3)
        ->and(Credits::for($this->person)->availableWithin()->available)->toBe(100)
        ->and(Credits::available($this->person))->toBe(1_000);

    $refusal = null;

    try {
        Credits::for($this->person)->hold(101);
    } catch (InsufficientCreditsException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->creditable->is($this->company))->toBeTrue()
        ->and($refusal->requestedBy?->is($this->person))->toBeTrue()
        ->and($refusal->blockedByParent())->toBeTrue()
        ->and($refusal->ownerType())->toBe($this->company->getMorphClass())
        ->and($refusal->ownerKey())->toBe($this->company->id)
        ->and($refusal->available)->toBe(100)
        ->and($refusal->requested)->toBe(-101)
        ->and($refusal->getMessage())->toBe('Insufficient credits in the budget of ['.$this->company->getMorphClass().'#'.$this->company->id.']: tried to deduct 101 but only 100 are available there.')
        ->and(CreditHold::query()->count())->toBe(0);
});

it('names the nearest owner that blocks', function (): void {
    Credits::reset($this->person, '2026-11', to: 10);
    $this->company->modifyCredits(-80);

    $refusal = null;

    try {
        $this->person->modifyCredits(-50);
    } catch (InsufficientCreditsException $exception) {
        $refusal = $exception;
    }

    // The person (10 left) and the company (20 left) both block 50: the person is named, as its own owner.
    expect($refusal?->creditable->is($this->person))->toBeTrue()
        ->and($refusal?->blockedByParent())->toBeFalse()
        ->and($refusal?->getMessage())->toBe('Insufficient credits: tried to deduct 50 but only 10 are available.')
        ->and(Credits::availableWithin($this->person)->limitedBy->is($this->person))->toBeTrue();
});

it('holds every level at once and returns the child level', function (): void {
    Event::fake([CreditsHeld::class]);

    $hold = Credits::for($this->person)->hold(30, description: 'AI call');
    $levels = CreditHold::query()->orderBy('chain_depth')->get();

    expect($hold->isLinked())->toBeFalse()
        ->and($hold->creditable_id)->toBe($this->person->id)
        ->and($levels)->toHaveCount(3)
        ->and($levels->pluck('chain_depth')->all())->toBe([0, 1, 2])
        ->and($levels->pluck('creditable_id')->all())->toBe([$this->person->id, $this->team->id, $this->company->id])
        ->and($levels[1]->originKey())->toBe($hold->id)
        ->and($levels[2]->originKey())->toBe($hold->id)
        ->and($levels[2]->isLinked())->toBeTrue()
        ->and($levels[2]->description)->toBe('AI call')
        ->and(levels($this->company, $this->team, $this->person))->toBe([[100, 30], [1_000, 30], [1_000, 30]])
        ->and(Credits::availableWithin($this->person)->available)->toBe(70);

    Event::assertDispatchedTimes(CreditsHeld::class, 3);
    Event::assertDispatched(CreditsHeld::class, fn (CreditsHeld $event): bool => $event->creditable->is($this->company) && $event->available === 70);
    Event::assertDispatched(CreditsHeld::class, fn (CreditsHeld $event): bool => $event->creditable->is($this->person) && $event->available === 970);
});

it('debits every level and fires an event for each', function (): void {
    Event::fake([CreditsModified::class]);

    $credit = $this->person->modifyCredits(-40, 'AI call');

    expect($credit->creditable_id)->toBe($this->person->id)
        ->and(levels($this->company, $this->team, $this->person))->toBe([[60, 0], [960, 0], [960, 0]]);

    Event::assertDispatchedTimes(CreditsModified::class, 3);
    Event::assertDispatched(CreditsModified::class, fn (CreditsModified $event): bool => $event->creditable->is($this->company) && $event->balance === 60);
});

it('keeps a grant with the owner it is for', function (): void {
    $this->person->modifyCredits(25);

    expect(levels($this->company, $this->team, $this->person))->toBe([[100, 0], [1_000, 0], [1_025, 0]]);
});

it('counts held credits of every level against a debit', function (): void {
    Credits::for($this->team)->hold(80);

    expect(fn () => $this->person->modifyCredits(-30))->toThrow(InsufficientCreditsException::class)
        ->and(levels($this->company, $this->team, $this->person))->toBe([[100, 80], [1_000, 80], [1_000, 0]]);
});

it('debits the chain when setTo lowers a child, and only the child when it raises it', function (): void {
    $this->person->setCreditsTo(970);
    $this->person->setCreditsTo(2_000);

    expect(levels($this->company, $this->team, $this->person))->toBe([[70, 0], [970, 0], [2_000, 0]])
        ->and(fn () => $this->person->setCreditsTo(1_000))->toThrow(InsufficientCreditsException::class);
});

it('resets an owner without touching its parents', function (): void {
    Credits::reset($this->person, '2026-11', to: 10);

    expect(levels($this->company, $this->team, $this->person))->toBe([[100, 0], [1_000, 0], [10, 0]]);
});

it('captures every level, firing events for each', function (): void {
    Event::fake([CreditHoldCaptured::class, CreditsModified::class]);
    $hold = Credits::for($this->person)->hold(30);

    $credit = Credits::capture($hold, 20);

    expect($credit->amount)->toBe(-20)
        ->and($credit->creditable_id)->toBe($this->person->id)
        ->and($hold->status)->toBe(CreditHoldStatus::Captured)
        ->and(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Captured])
        ->and(CreditHold::query()->pluck('captured_amount')->all())->toBe([20, 20, 20])
        ->and(levels($this->company, $this->team, $this->person))->toBe([[80, 0], [980, 0], [980, 0]]);

    Event::assertDispatchedTimes(CreditHoldCaptured::class, 3);
    Event::assertDispatchedTimes(CreditsModified::class, 3);
    Event::assertDispatched(CreditHoldCaptured::class, fn (CreditHoldCaptured $event): bool => $event->creditable->is($this->company) && $event->released === 10);
    Event::assertDispatched(CreditHoldCaptured::class, fn (CreditHoldCaptured $event): bool => $event->hold === $hold);
});

it('captures beyond the hold only with an overdraft, and then debits every level', function (): void {
    $hold = Credits::for($this->person)->hold(90);

    expect(fn () => Credits::capture($hold, 150))->toThrow(InvalidArgumentException::class, 'allowOverdraft');

    Credits::capture($hold, 150, allowOverdraft: true);

    expect(levels($this->company, $this->team, $this->person))->toBe([[-50, 0], [850, 0], [850, 0]])
        ->and(CreditHold::query()->pluck('captured_amount')->all())->toBe([150, 150, 150]);
});

it('captures beyond the hold through an overdrawing scope, with or without parents', function (): void {
    $hold = Credits::for($this->person)->hold(90);
    $user = User::query()->create(['name' => 'Solo']);
    $user->modifyCredits(10);
    $alone = Credits::for($user)->hold(10);

    Credits::for($this->person)->allowOverdraft()->capture($hold, 120);
    $alone->capture(15, allowOverdraft: true);

    expect(levels($this->company, $this->team, $this->person))->toBe([[-20, 0], [880, 0], [880, 0]])
        ->and($user->creditsBalance())->toBe(-5)
        ->and($alone->captured_amount)->toBe(15);
});

it('releases every level', function (): void {
    Event::fake([CreditHoldReleased::class]);
    $hold = Credits::for($this->person)->hold(30);

    Credits::release($hold);

    expect($hold->status)->toBe(CreditHoldStatus::Released)
        ->and(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Released])
        ->and(levels($this->company, $this->team, $this->person))->toBe([[100, 0], [1_000, 0], [1_000, 0]]);

    Event::assertDispatchedTimes(CreditHoldReleased::class, 3);
});

it('expires every level together', function (): void {
    Event::fake([CreditHoldExpired::class]);
    Credits::for($this->person)->hold(30, CarbonImmutable::now()->addMinute());

    expect(Credits::expireHolds(CarbonImmutable::now()->addHour()))->toBe(3)
        ->and(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Expired])
        ->and(Credits::expireHolds(CarbonImmutable::now()->addHours(2)))->toBe(0);

    Event::assertDispatchedTimes(CreditHoldExpired::class, 3);
});

it('refuses to resolve an ancestor level on its own', function (string $verb): void {
    Credits::for($this->person)->hold(30);
    $linked = CreditHold::query()->where('chain_depth', 2)->firstOrFail();

    expect(fn () => Credits::{$verb}($linked))->toThrow(LinkedCreditHoldException::class, "hold [{$linked->id}]")
        ->and(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Held]);
})->with(['capture', 'release']);

it('locks the chain from the top down', function (): void {
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'credit_locks')) {
            $locked[] = $query->bindings[array_key_last($query->bindings)];
        }
    });

    Credits::for($this->person)->hold(10);
    $hold = $locked;
    $locked = [];
    $this->person->modifyCredits(-10);
    $debit = $locked;

    $order = [$this->company->id, $this->team->id, $this->person->id];

    expect($hold)->toBe($order)
        ->and($debit)->toBe($order);
});

it('names parents through a resolver the app registers', function (): void {
    $user = User::query()->create(['name' => 'Grace']);
    $user->modifyCredits(500);
    // The resolver answers for every owner: it hands the budgets back to their own creditParent().
    Credits::resolveParentsUsing(fn ($owner) => $owner instanceof User ? $this->team : $owner->creditParent());

    $user->modifyCredits(-40);

    expect($user->creditsBalance())->toBe(460)
        ->and(levels($this->company, $this->team))->toBe([[60, 0], [960, 0]])
        ->and(Credits::availableWithin($user)->limitedBy->is($this->company))->toBeTrue();
});

it('refuses a cycle', function (): void {
    $this->company->update(['parent_id' => $this->person->id]);

    expect(fn () => $this->person->modifyCredits(-1))->toThrow(InvalidCreditParentException::class, 'form a cycle')
        ->and(levels($this->company, $this->team, $this->person))->toBe([[100, 0], [1_000, 0], [1_000, 0]]);
});

it('refuses a chain deeper than the configured limit', function (): void {
    config()->set('credits.parents.max_depth', 1);

    expect(fn () => Credits::availableWithin($this->person))->toThrow(InvalidCreditParentException::class, 'deeper than 1 levels')
        ->and(Credits::availableWithin($this->team)->levels)->toBe(2);
});

it('refuses a parent whose ledger is on another connection', function (): void {
    config()->set('database.connections.elsewhere', config('database.connections.'.config('database.default')));
    $user = User::query()->create(['name' => 'Lin']);
    $user->modifyCredits(5);
    $elsewhere = (new Budget)->setConnection('elsewhere')->forceFill(['id' => $this->company->id]);
    Credits::resolveParentsUsing(static fn ($owner) => $owner instanceof User ? $elsewhere : null);

    expect(fn () => $user->modifyCredits(-1))->toThrow(InvalidCreditParentException::class, 'another connection')
        ->and($user->creditsBalance())->toBe(5);
});

it('skips a level that was resolved on its own', function (string $verb, CreditHoldStatus $status): void {
    $hold = Credits::for($this->person)->hold(30);
    CreditHold::query()->where('chain_depth', 1)->update(['status' => CreditHoldStatus::Expired->value]);

    Credits::{$verb}($hold);

    expect(CreditHold::query()->orderBy('chain_depth')->pluck('status')->all())->toBe([$status, CreditHoldStatus::Expired, $status]);
})->with([
    'capture' => ['capture', CreditHoldStatus::Captured],
    'release' => ['release', CreditHoldStatus::Released],
]);

it('resolves a nested hold under preventLazyLoading, whichever way the hold was loaded', function (string $verb): void {
    // The suite's TestCase refuses lazy loading; a hold taken from a collection is the strict case.
    Credits::for($this->person)->hold(30, CarbonImmutable::now()->addMinute());
    Credits::for($this->person)->hold(5);
    $hold = CreditHold::query()->where('chain_depth', 0)->orderBy('id')->get()->first();

    match ($verb) {
        'capture' => Credits::capture($hold),
        'release' => Credits::release($hold),
        'expire' => Credits::expireHolds(CarbonImmutable::now()->addHour()),
    };

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(CreditHold::query()->where('amount', 30)->pluck('status')->unique()->all())->toHaveCount(1)
        ->and(CreditHold::query()->where('amount', 30)->value('status'))->not->toBe(CreditHoldStatus::Held);
})->with(['capture', 'release', 'expire']);
