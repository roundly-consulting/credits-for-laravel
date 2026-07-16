<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Tests\Fixtures\User;

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`, which
 * returns `''`. Every "does not leak" check was vacuous — passing against empty output.
 *
 * Credits' section carries no credentials, which is exactly why it is worth pinning: the
 * risk here is not an API key but the **ledger**. `credits.modifiable` holds host closures
 * that walk the host's own entities, and the section must report how many are registered
 * and never anything about what they resolve; nor may a balance or a creditable ever
 * render. `mustRender` is required and non-empty, so the negative half can never pass over
 * empty output.
 */
it('renders the credits section without leaking the ledger it guards', function (): void {
    config()->set('credits.modifiable', [
        static fn (): null => null,
    ]);

    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(4242);

    expect('credits')->toLeakNoSecrets(
        secrets: [
            // The host's resolver closures are described by count, never by what they
            // name or reach.
            User::class,
            'Ada',
            // No balance, and no ledger row, ever renders in an `about` section.
            '4242',
            (string) $user->credits()->value('id'),
        ],
        mustRender: [
            'Model',
            'Overdraft',
            'Minimum balance',
            'Default bucket',
            'Scale',
            'Modifiable resolvers',
            // The count itself must render — the positive proof that the resolver line is
            // reporting rather than silently empty.
            '1 registered',
        ],
    );
});
