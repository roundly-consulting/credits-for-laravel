<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Tests\Fixtures\CustomCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\SwappedCreditTestCase;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * The model-swap proof (S) for the `credits.model` seam, driven through the REAL flows.
 *
 * This replaces `Unit/Models/CustomCreditModelTest.php`, which set the config at runtime
 * and asserted `instanceof`. Both halves of that were too weak to catch the bugs this
 * class of test exists for:
 *
 *  - a runtime `config()->set()` leaves every observer the provider hung at boot on the
 *    packaged Credit (media #28);
 *  - `instanceof` passes for a row created as the packaged class — which never fires the
 *    host's model events (permissions #31). Only the concrete class, plus a `created`
 *    event counted on the subclass itself, proves the row was made as the host's model.
 *
 * The swap is applied before boot by {@see SwappedCreditTestCase},
 * which this directory is bound to — Pest binds a test case per directory, not per file.
 */
it('honours a host credit model through every ledger flow', function (): void {
    expect('credits.model')->toHonourModelSwap(CustomCredit::class, function (): array {
        $user = User::query()->create(['name' => 'Ada']);

        // A grant and a debit, through the trait's public API — the flows a host uses.
        $granted = $user->modifyCredits(100, 'signup bonus');
        $debited = $user->modifyCredits(-30);
        $set = $user->setCreditsTo(200);

        return [
            $granted,
            $debited,
            $set,
            // The morph relation hydrates through the seam too, not just the writes.
            ...$user->credits()->get()->all(),
        ];
    });
});

/**
 * The swap must survive the path that matters most for money: the overdraft guard's locked
 * read. If the guard queried the packaged model while the writes went through the host's,
 * the balance it guards would be derived from a different query than the ledger it writes.
 */
it('reads the balance through the swapped model under the overdraft guard', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(50);

    expect($user->creditsBalance())->toBe(50)
        ->and($user->credits()->first())->toBeInstanceOf(CustomCredit::class)
        // The guard runs its locked read and rejects — through the host's model.
        ->and(fn (): mixed => $user->modifyCredits(-80))
        ->toThrow(InsufficientCreditsException::class)
        ->and($user->creditsBalance())->toBe(50);
});

// The structural half of the seam — Credit is non-final, and `credits.model` really
// defaults to the packaged model — is pinned once in tests/ArchTest.php by
// `ArchPresets::swappableModelsAreNotFinal()`. It deliberately does NOT live here: that
// preset asserts the config *default*, which this directory has swapped away.
