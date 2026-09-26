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
    $user->modifyCredits(4242, 'confidential-ledger-note');

    expect('credits')->toLeakNoSecrets(
        secrets: [
            // The host's resolver closures are described by count, never by what they
            // name or reach.
            User::class,
            'Ada',
            // No balance, and no ledger row, ever renders in an `about` section.
            '4242',
            // A real column off the row that was just written — the ledger canary that
            // replaces the row's `id`. The id cannot be pinned now that
            // `credits.primary_key_type` defaults to bigint: it is the integer `1`, and a
            // one-character substring canary matches unrelated output ("1 registered"),
            // failing for a reason that has nothing to do with a leak.
            'confidential-ledger-note',
        ],
        mustRender: [
            'Model',
            // Both key axes render distinctly: the inbound PK and the outbound creditable
            // morph key are independent config keys, each defaulting to bigint.
            'Primary key type',
            'Creditable key type',
            'bigint',
            'Overdraft',
            'Minimum balance',
            'Default bucket',
            'Scale',
            // The rounding mode renders in its config spelling (a \RoundingMode case in
            // snake_case), which is what a host sets in CREDITS_ROUNDING.
            'Rounding',
            'half_away_from_zero',
            'Modifiable resolvers',
            // The count itself must render — the positive proof that the resolver line is
            // reporting rather than silently empty.
            '1 registered',
        ],
    );
});

it('reports a misconfigured rounding mode instead of failing the about command', function (): void {
    config()->set('credits.rounding', 'half_up');

    expect('credits')->toLeakNoSecrets(
        secrets: ['half_up'],
        mustRender: ['Rounding', 'INVALID'],
    );
});

it('normalises a native rounding mode set in config to its config spelling', function (): void {
    config()->set('credits.rounding', RoundingMode::HalfEven);

    expect('credits')->toLeakNoSecrets(
        secrets: ['HalfEven'],
        mustRender: ['Rounding', 'half_even'],
    );
});
