<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\Budget;
use RoundlyConsulting\Credits\Tests\Fixtures\SecondSession;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Sibling budgets race for their shared parent, on two real sessions. The first operation runs
 * in a transaction the test keeps open — every lock it took is held — while a forked second
 * session attempts the competing one, blocks on the parent, and decides only after the first
 * commits. Only a real engine shows it: SQLite serialises whole-database writes.
 */
$notPostgres = fn (): bool => DriverMatrix::driver() !== 'pgsql';

beforeEach(function (): void {
    config()->set('credits.parents.enabled', true);
    config()->set('credits.holds.enabled', true);
    config()->set('database.connections.probe', DriverMatrix::connectionConfig('pgsql'));
    DB::purge('probe');

    // The parent has 100; each sibling could spend 1 000 on its own.
    $this->company = Budget::under(null, 'Acme', 100);
    $this->alpha = Budget::under($this->company, 'Alpha', 1_000);
    $this->beta = Budget::under($this->company, 'Beta', 1_000);
});

/**
 * Run `$operation` and answer `<its result>/<lock statements it ran>`: a deadlock Postgres broke
 * is healed by the transaction's retry, so only the extra lock statements of the retry show it.
 *
 * @param  Closure(): mixed  $operation
 */
function countingLocks(Closure $operation): string
{
    $bumps = 0;
    DB::listen(static function (QueryExecuted $query) use (&$bumps): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'credit_locks')) {
            $bumps++;
        }
    });

    $result = $operation();

    return $result.'/'.$bumps;
}

function parentIsolation(?string $level): void
{
    if ($level !== null) {
        DB::statement("set session characteristics as transaction isolation level {$level}");
    }
}

it('lets exactly one of two sibling holds take the parent\'s last credits', function (?string $level): void {
    parentIsolation($level);
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file, $level): void {
        Credits::for($this->alpha)->hold(60);

        [$pid, $file] = SecondSession::run(fn (): mixed => Credits::for(Budget::query()->findOrFail($this->beta->id))->hold(60)->id, $level);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused')
        ->and(Credits::held($this->company))->toBe(60)
        ->and(Credits::held($this->beta))->toBe(0)
        ->and(Credits::availableWithin($this->beta)->available)->toBe(40)
        ->and(CreditHold::query()->count())->toBe(2);
})->with([
    'read committed' => [null],
    'repeatable read' => ['repeatable read'],
    'serializable' => ['serializable'],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('lets exactly one of two sibling debits take the parent\'s last credits', function (?string $level): void {
    parentIsolation($level);
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file, $level): void {
        $this->alpha->modifyCredits(-60);

        [$pid, $file] = SecondSession::run(fn (): mixed => Budget::query()->findOrFail($this->beta->id)->modifyCredits(-60)->amount, $level);

        SecondSession::waitUntilBlocked($file);
    });

    expect(SecondSession::outcome($pid, $file))->toBe('refused')
        ->and(Credits::balance($this->company))->toBe(40)
        ->and(Credits::balance($this->alpha))->toBe(940)
        ->and(Credits::balance($this->beta))->toBe(1_000);
})->with([
    'read committed' => [null],
    'repeatable read' => ['repeatable read'],
])->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('does not deadlock when two sessions hold the siblings in opposite orders', function (): void {
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file): void {
        Credits::for($this->alpha)->hold(10);

        [$pid, $file] = SecondSession::run(fn (): mixed => countingLocks(function (): int {
            $beta = Credits::for(Budget::query()->findOrFail($this->beta->id))->hold(10);
            $alpha = Credits::for(Budget::query()->findOrFail($this->alpha->id))->hold(10);

            return $beta->amount + $alpha->amount;
        }));

        SecondSession::waitUntilBlocked($file);

        // The second session waits on the shared parent, holding nothing of beta's yet — taken
        // child first, it would hold beta's lock here and this hold would deadlock against it.
        Credits::for($this->beta)->hold(10);
    });

    // Two holds of two levels each: four lock statements, none retried.
    expect(SecondSession::outcome($pid, $file))->toBe("done:'20/4'")
        ->and(Credits::held($this->company))->toBe(40)
        ->and(Credits::held($this->alpha))->toBe(20)
        ->and(Credits::held($this->beta))->toBe(20);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');

it('does not deadlock a child debit against a parent debit', function (): void {
    $pid = 0;
    $file = '';

    DB::transaction(function () use (&$pid, &$file): void {
        $this->company->modifyCredits(-10);

        [$pid, $file] = SecondSession::run(fn (): mixed => countingLocks(fn (): int => Budget::query()->findOrFail($this->alpha->id)->modifyCredits(-10)->amount));

        SecondSession::waitUntilBlocked($file);

        $this->alpha->modifyCredits(-10);
    });

    expect(SecondSession::outcome($pid, $file))->toBe("done:'-10/2'")
        ->and(Credits::balance($this->company))->toBe(70)
        ->and(Credits::balance($this->alpha))->toBe(980);
})->skip($notPostgres, 'needs a real engine (and pcntl + posix for the second process)');
