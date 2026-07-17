<?php

declare(strict_types=1);

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
