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
