<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Exceptions\CreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Support\RoundingModes;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Credits shipped with no architecture test at all, so every preset here is a new guard
 * rather than a replacement — including the two that matter most for a package whose
 * model is a documented seam and whose `require` a host installs at runtime.
 */
ArchPresets::strictTypes('RoundlyConsulting\Credits');

/**
 * Three deliberate extension points are exempt: Credit, which `credits.model` invites a
 * host to subclass (pinned by the preset below instead); CreditsException, the base
 * every credits error extends so a host can catch them uniformly; and CreditsManager, which
 * the package's own CreditsFake extends — that is how `Credits::fake()` stays a subtype of
 * what constructor injection asks for.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Credits', [Credit::class, CreditsException::class, CreditsManager::class]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal: `final` on a config-swappable
 * model is a PHP fatal the moment a host uses the seam the config documents. The preset
 * also pins that `credits.model` really defaults to the packaged model, so the seam can't
 * rot in the other direction either.
 */
ArchPresets::swappableModelsAreNotFinal([
    Credit::class => 'credits.model',
]);

/**
 * Credits does no cryptography; the ban is a standing guard against a ledger id or token
 * scheme being hand-rolled here rather than in crypto-for-laravel.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Credits');

/**
 * `credits.model` resolves through the CreditModel seam in Support. Adopted here rather
 * than rejected as jwt rejected it: credits has the shape the preset is aimed at — a real
 * Eloquent model behind a `*_model` key — so the stray-literal half has something to say,
 * and nothing in credits needs the late static binding the preset bans (the seam returns a
 * class-string and every call site goes through `CreditModel::class()`).
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support');

/**
 * The morph-key seam, guarded. Credits migrated its owner morph column off raw
 * `$table->morphs()` onto `morphKey($name, KeyType::…)` so a uuid/ulid host can flip its
 * whole graph coherently — a hardcoded bigint id breaks those hosts on Postgres, and SQLite
 * type affinity hides it. This pin reds if a future migration reintroduces a raw morph and
 * bypasses the seam.
 */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

/**
 * The Dependency Policy as a test. No `alsoAllow`: credits' `require` ships only
 * php/illuminate/roundly, and the workflow installs test tooling with `--dev`, so nothing
 * legitimately lands in `require` that this must forgive. If this goes red, the graph is
 * wrong — never widen the allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * HasCredits delegates every read and write to CreditsManager, never an action, so
 * `Credits::fake()` records changes made through the model too.
 */
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Credits');

/**
 * Money's public API only. money-for-laravel marks its engine (`IntegerString`,
 * `Calculator`, `Rounder`, `DecimalString`, the casts, …) `@internal`: those may change
 * shape at any time. Credits builds on the documented seams — `Math\MinorUnits`,
 * `Support\RoundingModes`, `Money`, `Currency`, the exceptions — and this pins it there.
 * Every money class named anywhere in `src/` (import or inline FQCN) is checked, and the
 * two seams the formatting core rests on must be among them, so the rule cannot pass over
 * an empty scan.
 */
it('references no @internal money class, only its public api', function (): void {
    $referenced = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        preg_match_all('/RoundlyConsulting\\\\Money\\\\[A-Za-z0-9_\\\\]+/', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[0] as $class) {
            $referenced[] = ltrim($class, '\\');
        }
    }

    $referenced = array_values(array_unique($referenced));

    expect($referenced)->toContain(MinorUnits::class, RoundingModes::class);

    foreach ($referenced as $class) {
        expect(class_exists($class) || interface_exists($class))->toBeTrue("{$class} does not exist")
            ->and(str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal'))
            ->toBeFalse("{$class} is @internal to money-for-laravel");
    }
});
