<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\LockRecordingCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * The money boundary. The balance is never a stored column — it is the sum of an
 * append-only ledger — so the two failure modes to pin are: a debit that overdraws
 * because it decided against a stale balance, and a change that is lost because a
 * concurrent write overwrote it.
 */

/**
 * Append a competing ledger row exactly once, from inside the transaction that is
 * already mid-flight — i.e. between the overdraft guard's balance read and the ledger
 * write it authorised. This is the interleaving a second connection would produce.
 */
function interleaveOnce(User $user, int $amount): void
{
    $fired = false;

    Credit::creating(function () use ($user, $amount, &$fired): void {
        if ($fired) {
            return;
        }

        $fired = true;

        $user->credits()->create([
            'bucket' => 'default',
            'amount' => $amount,
            'description' => 'competing write',
        ]);
    });
}

afterEach(function (): void {
    Credit::flushEventListeners();
    LockRecordingCredit::resetLocks();
});

it('persists a change as an append-only delta row, never an absolute balance write', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    DB::enableQueryLog();

    $user->modifyCredits(-40);

    $statements = array_map(
        static fn (array $query): string => strtolower((string) $query['query']),
        DB::getQueryLog(),
    );

    DB::disableQueryLog();

    $writes = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'insert into') || str_starts_with($sql, 'update '),
    ));

    // The owner-lock bump, then a single INSERT of the delta. Nothing rewrites a balance, so
    // there is no read-modify-write to lose: two concurrent changes both land as rows. The
    // lock row holds a version counter only — never a balance.
    expect($writes)->toHaveCount(2)
        ->and($writes[0])->toStartWith('update "credit_locks" set "version" = "version" + 1')
        ->and($writes[1])->toStartWith('insert into')->toContain('"credits"')
        ->and($user->creditsBalance())->toBe(60);
});

it('reads the ledger under a row lock, inside the transaction, before the guard decides', function (): void {
    config()->set('credits.model', LockRecordingCredit::class);

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    LockRecordingCredit::resetLocks();

    $user->modifyCredits(-40);

    // Exactly one locked read — the overdraft guard's — and it happens at transaction
    // depth 1, so a racing debit blocks on it instead of deciding against a stale sum.
    expect(LockRecordingCredit::$locks)->toBe([1]);
});

it('reads every change under the lock, so the event balance is exact', function (): void {
    config()->set('credits.model', LockRecordingCredit::class);

    $user = User::query()->create(['name' => 'Ada']);

    $user->modifyCredits(100);
    $user->modifyCredits(-10, allowOverdraft: true);

    expect(LockRecordingCredit::$locks)->toBe([1, 1]);
});

// Regression: setTo() computed its delta from a plain SUM. On MySQL's default REPEATABLE READ
// that is a consistent read, answered from a snapshot a host transaction may have opened
// before the lock wait — so two racing "set to 500" calls each applied +400. A locked read is
// a current read on every engine.
it('reads the setTo balance under a row lock, inside its transaction', function (): void {
    config()->set('credits.model', LockRecordingCredit::class);

    $user = User::query()->create(['name' => 'Ada']);
    $user->setCreditsTo(100);

    LockRecordingCredit::resetLocks();

    $user->setCreditsTo(500);

    // setTo's own read at depth 1, then the nested modify's guard read inside it.
    expect(LockRecordingCredit::$locks)->toBe([1, 2])
        ->and($user->creditsBalance())->toBe(500);
});

it('bumps the owner lock once per change, creating it on the first', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $other = User::query()->create(['name' => 'Bob']);

    $user->modifyCredits(100);
    $user->modifyCredits(-10);
    $other->modifyCredits(5, bucket: 'promotional');

    expect(DB::table('credit_locks')->count())->toBe(2)
        ->and(DB::table('credit_locks')->where('creditable_id', $user->id)->value('version'))->toBe(2)
        ->and(DB::table('credit_locks')->where('creditable_id', $other->id)->value('version'))->toBe(1);
});

it('serialises racing debits so the second cannot overdraw the balance', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    // Once the lock has serialised them, the second debit re-derives the balance from
    // the ledger — it never reuses the sum it read before the first debit committed.
    $user->modifyCredits(-100);

    expect(fn (): mixed => $user->modifyCredits(-100))
        ->toThrow(InsufficientCreditsException::class)
        ->and($user->creditsBalance())->toBe(0)
        ->and($user->credits()->count())->toBe(2);
});

it('never loses a credit that lands between the balance read and the ledger write', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    interleaveOnce($user, 30);

    $user->modifyCredits(50);

    // 100 + 30 + 50. A design that wrote the balance it read at lock time would have
    // persisted 150 and silently dropped the competing grant.
    expect($user->creditsBalance())->toBe(180)
        ->and($user->credits()->count())->toBe(3);
});

it('never loses a debit that lands between the balance read and the ledger write', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    interleaveOnce($user, -30);

    $user->modifyCredits(-50);

    // 100 - 30 - 50. Both deductions survive; neither overwrites the other.
    expect($user->creditsBalance())->toBe(20)
        ->and($user->credits()->count())->toBe(3);
});

it('rolls the transaction back so a rejected deduction writes no ledger row', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(10);

    expect(fn (): mixed => $user->modifyCredits(-50))
        ->toThrow(InsufficientCreditsException::class)
        ->and($user->credits()->count())->toBe(1)
        ->and($user->creditsBalance())->toBe(10)
        ->and(DB::transactionLevel())->toBe(0);
});

it('keeps buckets isolated when a debit races a credit in another bucket', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(50, bucket: 'purchased');

    expect(fn (): mixed => $user->modifyCredits(-10, bucket: 'promotional'))
        ->toThrow(InsufficientCreditsException::class)
        ->and($user->creditsBalance(bucket: 'purchased'))->toBe(50)
        ->and($user->totalCreditsBalance())->toBe(50);
});
