<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Two debits racing on two real sessions, end to end.
 *
 * LockSerialisesOnPostgresTest proves the ledger rows are locked; that alone does not stop
 * an overdraft. Under Postgres' READ COMMITTED a `SELECT … FOR UPDATE` that waited for a
 * lock still answers from the snapshot taken when it *started*: it re-checks the rows it
 * was waiting on, but a ledger row the first debit INSERTED is invisible to it. The second
 * debit then decides against the balance from before the first one — and overdraws.
 *
 * So this forks a real second process with its own connection, lets it block behind the
 * first debit's still-open transaction, commits the first, and checks what the second
 * decided. Only a real engine can show it; SQLite serialises whole-database writes.
 */
function raceDebit(User $user, int $amount, string $resultFile): int
{
    return raceOnSecondSession($user, fn (User $racer): mixed => $racer->modifyCredits($amount), 'debited', $resultFile);
}

/**
 * Run `$operation` on the user from a forked second process with its own connection and
 * write what happened to `$resultFile`: `$done`, `refused` or the error.
 *
 * @param  Closure(User): mixed  $operation
 */
function raceOnSecondSession(User $user, Closure $operation, string $done, string $resultFile): int
{
    $pid = pcntl_fork();

    if ($pid !== 0) {
        return $pid;
    }

    // The child: a separate session. Never touch the parent's connection — closing it
    // here would terminate the parent's session on the server.
    $outcome = 'error';

    try {
        config()->set('database.connections.racer', DriverMatrix::connectionConfig('pgsql'));
        DB::setDefaultConnection('racer');

        $racer = User::on('racer')->findOrFail($user->getKey());
        $operation($racer);
        $outcome = $done;
    } catch (InsufficientCreditsException) {
        $outcome = 'refused';
    } catch (Throwable $e) {
        $outcome = 'error: '.$e->getMessage();
    }

    file_put_contents($resultFile, $outcome);

    // No shutdown handlers, no destructors: nothing of the parent's state is torn down.
    posix_kill(posix_getpid(), SIGKILL);

    return 0;
}

/**
 * Until the racer is blocked on a lock — or has already finished because nothing blocked it.
 */
function waitForTheRacer(string $resultFile): void
{
    $probe = DB::connection('probe');

    for ($i = 0; $i < 100; $i++) {
        clearstatcache();

        if (filesize($resultFile) > 0) {
            return;
        }

        $waiting = $probe->selectOne(
            "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'",
        );

        if ((int) $waiting->n > 0) {
            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException('The racing debit neither blocked nor finished.');
}

beforeEach(function (): void {
    config()->set('database.connections.probe', DriverMatrix::connectionConfig('pgsql'));
    DB::purge('probe');
});

it('refuses a second debit that waited on the first instead of deciding on a stale balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    $resultFile = (string) tempnam(sys_get_temp_dir(), 'credits-race-');
    $pid = 0;

    DB::transaction(function () use ($user, $resultFile, &$pid): void {
        // The first debit, its transaction still open: every lock it took is held.
        $user->modifyCredits(-60);

        $pid = raceDebit($user, -60, $resultFile);

        waitForTheRacer($resultFile);
    });

    pcntl_waitpid($pid, $status);
    $outcome = (string) file_get_contents($resultFile);
    @unlink($resultFile);

    // 100 − 60 leaves 40: a second −60 must be refused, not written against the 100 it
    // would have seen had it answered from the snapshot it started with.
    expect($outcome)->toBe('refused')
        ->and($user->creditsBalance())->toBe(40)
        ->and($user->credits()->count())->toBe(2);
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs a real engine (and pcntl + posix for the second process)');

it('serialises two debits on an empty bucket when the floor is below zero', function (): void {
    config()->set('credits.minimum_balance', -100);

    $user = User::query()->create(['name' => 'Ada']);

    $resultFile = (string) tempnam(sys_get_temp_dir(), 'credits-race-');
    $pid = 0;

    DB::transaction(function () use ($user, $resultFile, &$pid): void {
        // No ledger row exists yet, so there is no ledger row to lock.
        $user->modifyCredits(-60);

        $pid = raceDebit($user, -60, $resultFile);

        waitForTheRacer($resultFile);
    });

    pcntl_waitpid($pid, $status);
    $outcome = (string) file_get_contents($resultFile);
    @unlink($resultFile);

    // −60 then −60 would be −120, below the −100 floor.
    expect($outcome)->toBe('refused')
        ->and($user->creditsBalance())->toBe(-60);
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs a real engine (and pcntl + posix for the second process)');

// Regression: setTo() read the balance without a lock and outside any transaction, so two
// racing "set to 500" calls on a balance of 100 each computed +400 and the owner ended on 900.
it('serialises two racing setTo calls so the second sees the first', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    $resultFile = (string) tempnam(sys_get_temp_dir(), 'credits-race-');
    $pid = 0;

    DB::transaction(function () use ($user, $resultFile, &$pid): void {
        // The first set, its transaction still open: every lock it took is held.
        $user->setCreditsTo(500);

        $pid = raceOnSecondSession($user, fn (User $racer): mixed => $racer->setCreditsTo(500), 'set', $resultFile);

        waitForTheRacer($resultFile);
    });

    pcntl_waitpid($pid, $status);
    $outcome = (string) file_get_contents($resultFile);
    @unlink($resultFile);

    // The racer waited, then found the balance already at 500: a no-op, not a second +400.
    expect($outcome)->toBe('set')
        ->and($user->creditsBalance())->toBe(500)
        ->and($user->credits()->count())->toBe(2);
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs a real engine (and pcntl + posix for the second process)');
