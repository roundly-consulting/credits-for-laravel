<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('dispatches once per recorded change with the resulting balance', function (): void {
    Event::fake();

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    Event::assertDispatched(
        CreditsModified::class,
        fn (CreditsModified $event): bool => $event->creditable->is($user)
            && $event->credit->amount === 100
            && $event->balance === 100,
    );
});

it('does not dispatch when setCreditsTo is a no-op', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(40);

    Event::fake();

    $user->setCreditsTo(40);

    Event::assertNotDispatched(CreditsModified::class);
});

/**
 * The payload is the balance THIS change produced, read under the owner lock inside the
 * transaction — not a fresh sum taken after the commit, which could already include another
 * writer's rows (and hand two events the same "resulting" balance).
 */
it('carries the balance this change produced, not one read after the commit', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    $competed = false;

    // A racing writer that commits between our commit and anything read afterwards.
    Credit::created(function () use ($user, &$competed): void {
        if ($competed) {
            return;
        }

        $competed = true;

        DB::afterCommit(fn (): Credit => $user->credits()->create(['bucket' => 'default', 'amount' => 30]));
    });

    $balances = [];
    Event::listen(CreditsModified::class, function (CreditsModified $event) use (&$balances): void {
        $balances[] = $event->balance;
    });

    $user->modifyCredits(-40);

    expect($balances)->toBe([60])
        ->and($user->creditsBalance())->toBe(90);

    Credit::flushEventListeners();
});

it('carries the exact running balance on consecutive changes', function (): void {
    $balances = [];
    Event::listen(CreditsModified::class, function (CreditsModified $event) use (&$balances): void {
        $balances[] = $event->balance;
    });

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);
    $user->modifyCredits(-30);
    $user->setCreditsTo(10);
    $user->modifyCredits(5, bucket: 'promotional');

    expect($balances)->toBe([100, 70, 10, 5]);
});

/**
 * Listeners hear only changes that committed. The event used to fire inside setTo()'s own
 * transaction and inside any host transaction, so a rolled-back change had already been
 * announced (and a throwing listener rolled the change back).
 */
it('reaches no listener when the host transaction rolls back', function (): void {
    $heard = [];
    Event::listen(CreditsModified::class, function (CreditsModified $event) use (&$heard): void {
        $heard[] = $event->balance;
    });

    $user = User::query()->create(['name' => 'Ada']);

    expect(fn (): mixed => DB::transaction(function () use ($user): never {
        $user->modifyCredits(10);

        throw new RuntimeException('host rolls back');
    }))->toThrow(RuntimeException::class, 'host rolls back')
        ->and($heard)->toBe([])
        ->and($user->credits()->count())->toBe(0);
});

it('dispatches a setTo change after its transaction has committed', function (): void {
    $levels = [];
    Event::listen(CreditsModified::class, function (CreditsModified $event) use (&$levels): void {
        $levels[] = [$event->balance, DB::transactionLevel()];
    });

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(10);
    $user->setCreditsTo(50);

    expect($levels)->toBe([[10, 0], [50, 0]]);
});

it('dispatches once a host transaction commits', function (): void {
    $levels = [];
    Event::listen(CreditsModified::class, function (CreditsModified $event) use (&$levels): void {
        $levels[] = [$event->balance, DB::transactionLevel()];
    });

    $user = User::query()->create(['name' => 'Ada']);

    DB::transaction(function () use ($user, &$levels): void {
        $user->modifyCredits(7);

        expect($levels)->toBe([]);
    });

    expect($levels)->toBe([[7, 0]]);
});
