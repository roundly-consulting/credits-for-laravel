<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;
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
