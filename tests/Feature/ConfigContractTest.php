<?php

declare(strict_types=1);

/**
 * The config contract credits never had, pinned in both directions:
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose whole
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`;
 *    330 tests stayed green because the suite set the same wrong key. Credits is the
 *    package on the *other* side of that bug — its ledger is what store credit spends.
 *  - reverse — every shipped leaf is read. A documented key nothing reads is dead
 *    config that lies to the host: media #27's `max_file_size` cap that never applied,
 *    alerts #24's thrice-documented `escalation` key.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/credits.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../database'], [
        // `credits.model` is read through the toolkit's `ModelResolver::for('credits.model',
        // …)` seam rather than a `config()` call. It is a real read that drives the whole
        // model swap, but it is not a `config(` token, so the prefix is what makes it
        // visible to the scraper.
        'extraReadPrefixes' => ['credits.'],

        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls
        // `config('credits.…')` for real, and for `credits.rounding` and `credits.scale`
        // the reads that matter live in FormatCreditsAction anyway. Excluding it would
        // only discard readers and weaken the reverse direction for nothing.
    ]);
});
