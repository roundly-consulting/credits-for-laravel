<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/** The seam's ulid leg. @see UuidKeyTest */
it('mints and stores a ulid primary key when configured for ulid', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $credit = $user->credits()->create(['amount' => 50]);

    expect($credit->id)->toBeString()->toHaveLength(26)
        ->and($credit->getKeyType())->toBe('string')
        ->and($credit->getIncrementing())->toBeFalse()
        ->and($credit->uniqueIds())->toBe(['id'])
        ->and($credit->fresh()->id)->toBe($credit->id);
});

it('round-trips a ulid-keyed credit through the balance query', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->credits()->create(['amount' => 150]);
    $user->credits()->create(['amount' => -50]);

    expect($user->creditsBalance())->toBe(100);
});

it('keys holds and reset markers by the configured ulid key, and links the ledger rows', function (): void {
    config()->set('credits.holds.enabled', true);
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    $hold = Credits::for($user)->hold(30);
    $credit = Credits::capture($hold, 10);
    $reset = Credits::reset($user, '2026-11', to: 5);

    expect($hold->id)->toBeString()->toHaveLength(26)
        ->and($hold->fresh()?->credit_id)->toEqual($credit->id)
        ->and(DB::table('credit_resets')->value('credit_id'))->toEqual($reset->credit?->id)
        ->and($user->creditsBalance())->toBe(5);
});
