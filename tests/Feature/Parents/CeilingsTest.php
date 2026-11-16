<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Events\CreditsHeld;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Tests\Fixtures\Budget;

/*
 * Extra ceilings (`alsoWithin`): an AI agent's own budget caps the same call as the person's
 * chain of parents, though it is no ancestor of the person.
 */

beforeEach(function (): void {
    config()->set('credits.parents.enabled', true);
    config()->set('credits.holds.enabled', true);

    $this->company = Budget::under(null, 'Acme', 100);
    $this->team = Budget::under($this->company, 'Research', 1_000);
    $this->person = Budget::under($this->team, 'Ada', 1_000);
    $this->agent = Budget::under(null, 'Agent', 30);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Credits::resolveParentsUsing(null);
});

/** Each budget's balance and held credits. */
function ceilingLevels(Budget ...$budgets): array
{
    return array_map(static fn (Budget $budget): array => [Credits::balance($budget), Credits::held($budget)], $budgets);
}

function refusal(Closure $call): InsufficientCreditsException
{
    try {
        $call();
    } catch (InsufficientCreditsException $exception) {
        return $exception;
    }

    throw new RuntimeException('Nothing was refused.');
}

it('blocks a call the chain would allow, naming the ceiling', function (): void {
    $refusal = refusal(fn () => Credits::for($this->person)->alsoWithin($this->agent)->hold(50));

    expect($refusal->creditable->is($this->agent))->toBeTrue()
        ->and($refusal->blockedByCeiling())->toBeTrue()
        ->and($refusal->blockedByParent())->toBeFalse()
        ->and($refusal->requestedBy?->is($this->person))->toBeTrue()
        ->and($refusal->available)->toBe(30)
        ->and($refusal->getMessage())->toContain('budget of ['.$this->agent->getMorphClass().'#'.$this->agent->id.']')
        ->and(CreditHold::query()->count())->toBe(0)
        // The chain alone allows it.
        ->and(Credits::for($this->person)->hold(50)->amount)->toBe(50);
});

it('refuses a debit over the ceiling through the change data too', function (): void {
    $refusal = refusal(fn () => Credits::modify($this->person, new CreditChangeData(-31, alsoWithin: [$this->agent])));

    expect($refusal->blockedByCeiling())->toBeTrue()
        ->and(ceilingLevels($this->company, $this->person, $this->agent))->toBe([[100, 0], [1_000, 0], [30, 0]]);
});

it('reads the smallest available along the chain and the ceilings', function (): void {
    $within = Credits::availableWithin($this->person, alsoWithin: [$this->agent]);

    expect($within->available)->toBe(30)
        ->and($within->limitedBy->is($this->agent))->toBeTrue()
        ->and($within->limitedByCeiling)->toBeTrue()
        ->and($within->limitedByParent($this->person))->toBeFalse()
        ->and($within->levels)->toBe(4)
        ->and(Credits::for($this->person)->alsoWithin($this->agent)->availableWithin()->available)->toBe(30)
        ->and(Credits::availableWithin($this->person)->limitedByCeiling)->toBeFalse()
        ->and(Credits::availableWithin($this->person)->available)->toBe(100);
});

it('holds every level, the ceiling included, and captures over the hold on all of them', function (): void {
    Event::fake([CreditsHeld::class, CreditHoldCaptured::class, CreditsModified::class]);

    $hold = Credits::for($this->person)->alsoWithin($this->agent)->hold(20);

    expect(CreditHold::query()->count())->toBe(4)
        ->and(CreditHold::query()->where('creditable_id', $this->agent->id)->sole()->originKey())->toBe($hold->id)
        ->and(ceilingLevels($this->company, $this->team, $this->person, $this->agent))->toBe([[100, 20], [1_000, 20], [1_000, 20], [30, 20]]);

    Event::assertDispatchedTimes(CreditsHeld::class, 4);

    Credits::capture($hold, 50, allowOverdraft: true);

    expect(ceilingLevels($this->company, $this->team, $this->person, $this->agent))->toBe([[50, 0], [950, 0], [950, 0], [-20, 0]])
        ->and(CreditHold::query()->pluck('status')->unique()->all())->toBe([CreditHoldStatus::Captured]);

    Event::assertDispatchedTimes(CreditHoldCaptured::class, 4);
    Event::assertDispatched(CreditHoldCaptured::class, fn (CreditHoldCaptured $event): bool => $event->creditable->is($this->agent));
});

it('takes extra ceilings through the hold data too', function (): void {
    $hold = Credits::hold($this->person, new CreditHoldData(10, alsoWithin: [$this->agent]));

    expect(Credits::held($this->agent))->toBe(10);

    Credits::release($hold);

    expect(ceilingLevels($this->company, $this->person, $this->agent))->toBe([[100, 0], [1_000, 0], [30, 0]]);
});

it('debits every level and keeps a grant with the owner', function (): void {
    Credits::for($this->person)->alsoWithin($this->agent)->deduct(10);
    Credits::for($this->person)->alsoWithin($this->agent)->add(5);

    expect(ceilingLevels($this->company, $this->team, $this->person, $this->agent))->toBe([[90, 0], [990, 0], [995, 0], [20, 0]]);
});

it('releases and expires every level, the ceiling included', function (): void {
    $released = Credits::for($this->person)->alsoWithin($this->agent)->hold(10);
    Credits::release($released);
    Credits::for($this->person)->alsoWithin($this->agent)->hold(10, CarbonImmutable::now()->addMinute());

    expect(Credits::expireHolds(CarbonImmutable::now()->addHour()))->toBe(4)
        ->and(ceilingLevels($this->company, $this->team, $this->person, $this->agent))->toBe([[100, 0], [1_000, 0], [1_000, 0], [30, 0]])
        ->and(CreditHold::query()->where('creditable_id', $this->agent->id)->pluck('status')->all())->toBe([CreditHoldStatus::Released, CreditHoldStatus::Expired]);
});

it('counts a ceiling\'s own parents, each owner once', function (): void {
    $agent = Budget::under($this->company, 'Company agent', 500);

    Credits::for($this->person)->alsoWithin($agent, $this->person, $this->team)->deduct(10);

    // The company is the person's ancestor and the agent's parent: debited once.
    expect(ceilingLevels($this->company, $this->team, $this->person, $agent))->toBe([[90, 0], [990, 0], [990, 0], [490, 0]]);
});

it('applies extra ceilings with parents switched off', function (): void {
    config()->set('credits.parents.enabled', false);

    expect(refusal(fn () => Credits::for($this->person)->alsoWithin($this->agent)->deduct(31))->blockedByCeiling())->toBeTrue();

    $hold = Credits::for($this->person)->alsoWithin($this->agent)->hold(20);
    Credits::capture($hold);

    expect(ceilingLevels($this->company, $this->team, $this->person, $this->agent))->toBe([[100, 0], [1_000, 0], [980, 0], [10, 0]]);
});

it('locks the levels in one global order, however they are named', function (): void {
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'credit_locks')) {
            $locked[] = $query->bindings[array_key_last($query->bindings)];
        }
    });

    Credits::for($this->person)->alsoWithin($this->agent)->hold(1);
    $first = $locked;
    $locked = [];
    Credits::for($this->agent)->alsoWithin($this->person)->hold(1);
    $second = $locked;

    // Nearest the top first, ties by key: the company and the agent (both tops), then the team, then the person.
    $tops = [$this->company->id, $this->agent->id];
    sort($tops);

    expect($first)->toBe([...$tops, $this->team->id, $this->person->id])
        ->and($second)->toBe($first);
});

it('refuses a ceiling whose ledger is on another connection', function (): void {
    config()->set('database.connections.elsewhere', config('database.connections.'.config('database.default')));
    $elsewhere = (new Budget)->setConnection('elsewhere')->forceFill(['id' => $this->agent->id]);

    expect(fn () => Credits::for($this->person)->alsoWithin($elsewhere)->deduct(1))->toThrow(InvalidCreditParentException::class, 'another connection')
        ->and(Credits::balance($this->person))->toBe(1_000);
});

it('walks ceilings in the fake too', function (): void {
    $fake = Credits::fake();

    expect(fn () => Credits::for($this->person)->alsoWithin($this->agent)->hold(31))->toThrow(InsufficientCreditsException::class);

    Credits::for($this->person)->alsoWithin($this->agent)->hold(20);
    Credits::for($this->person)->alsoWithin($this->agent)->deduct(5);

    expect(Credits::availableWithin($this->person, alsoWithin: [$this->agent])->available)->toBe(5)
        ->and(Credits::availableWithin($this->person, alsoWithin: [$this->agent])->limitedByCeiling)->toBeTrue();

    $fake->assertHeld($this->agent, 20);
    $fake->assertDeducted($this->agent, 5);
    $fake->assertDeducted($this->company, 5);
});

it('finds no other levels where the linking migration has not run', function (): void {
    config()->set('database.connections.bare', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    Schema::connection('bare')->create('credit_holds', function (Blueprint $table): void {
        $table->id();
    });
    CreditHolds::forgetSchema();

    $hold = (new CreditHold)->setConnection('bare')->forceFill(['id' => 1]);

    expect(CreditHolds::linked($hold))->toBe([]);

    CreditHolds::forgetSchema();
});
