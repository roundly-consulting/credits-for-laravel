<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\SecondSession;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Holds race like debits, on two real sessions. The first operation runs in a transaction the
 * test keeps open — every lock it took is held — while a forked second session attempts the
 * competing operation, blocks, and decides only after the first commits. Only a real engine
 * shows it: SQLite serialises whole-database writes.
 */
$notPostgres = fn (): bool => DriverMatrix::driver() !== 'pgsql';

beforeEach(function (): void {
    config()->set('credits.holds.enabled', true);
    config()->set('database.connections.probe', DriverMatrix::connectionConfig('pgsql'));
    DB::purge('probe');

    $this->user = User::query()->create(['name' => 'Ada']);
    $this->user->modifyCredits(100);
});

function isolation(?string $level): void
{
    if ($level !== null) {
        DB::statement("set session characteristics as transaction isolation level {$level}");
    }
}

it('refuses a second hold that would reserve more than the balance', function (?string $level): void {
    isolation($level);
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file, $level): void {
        Credits::for($this->user)->hold(60);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::for(User::query()->findOrFail($this->user->id))->hold(60)->id, $level);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused')
        ->and(Credits::held($this->user))->toBe(60)
        ->and(Credits::available($this->user))->toBe(40)
        ->and(CreditHold::query()->count())->toBe(1);
})->with([
    'read committed' => [null],
    'repeatable read' => ['repeatable read'],
    'serializable' => ['serializable'],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('refuses a hold that races a debit for the same credits', function (): void {
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file): void {
        $this->user->modifyCredits(-60);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::for(User::query()->findOrFail($this->user->id))->hold(60)->id);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused')
        ->and(Credits::balance($this->user))->toBe(40)
        ->and(CreditHold::query()->count())->toBe(0);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('refuses a debit that races a hold for the same credits', function (): void {
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file): void {
        Credits::for($this->user)->hold(60);

        [$pid, $file] = SecondSession::run(fn (): mixed => User::query()->findOrFail($this->user->id)->modifyCredits(-60)->amount);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused')
        ->and(Credits::balance($this->user))->toBe(100)
        ->and(Credits::available($this->user))->toBe(40);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('captures a hold once when two sessions capture it', function (?string $level): void {
    isolation($level);
    $hold = Credits::for($this->user)->hold(30);
    $pid = 0;
    $file = '';

    DB::transaction(function () use ($hold, &$pid, &$file, $level): void {
        Credits::capture($hold);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::capture(CreditHold::query()->findOrFail($hold->id))->amount, $level);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('not-active:captured')
        ->and(Credits::balance($this->user))->toBe(70)
        ->and($this->user->credits()->count())->toBe(2)
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Captured);
})->with([
    'read committed' => [null],
    'repeatable read' => ['repeatable read'],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('lets a capture win over a sweep that races it', function (): void {
    $hold = Credits::for($this->user)->hold(30, now()->addMinute());
    $pid = 0;
    $file = '';

    DB::transaction(function () use ($hold, &$pid, &$file): void {
        Credits::capture($hold);

        // The sweep runs "an hour later", when the hold would be stale — unless captured.
        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::expireHolds(CarbonImmutable::now()->addHour(), 'racer'));

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('done:0')
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Captured)
        ->and(Credits::balance($this->user))->toBe(70);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('refuses a capture that loses to the sweep', function (): void {
    $hold = Credits::for($this->user)->hold(30, now()->addMinute());
    $pid = 0;
    $file = '';

    DB::transaction(function () use ($hold, &$pid, &$file): void {
        expect(Credits::expireHolds(CarbonImmutable::now()->addHour()))->toBe(1);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::capture(CreditHold::query()->findOrFail($hold->id))->amount);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('not-active:expired')
        ->and($hold->fresh()?->status)->toBe(CreditHoldStatus::Expired)
        ->and(Credits::balance($this->user))->toBe(100)
        ->and($this->user->credits()->count())->toBe(1);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('resets a bucket once when two runs race in one period', function (): void {
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file): void {
        Credits::reset($this->user, '2026-11', to: 10);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::reset(User::query()->findOrFail($this->user->id), '2026-11', to: 10)->reset);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('done:false')
        ->and(Credits::balance($this->user))->toBe(10)
        ->and($this->user->credits()->count())->toBe(2)
        ->and(DB::table('credit_resets')->count())->toBe(1);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');
