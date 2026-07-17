<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Exceptions\CreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Credits shipped with no architecture test at all, so every preset here is a new guard
 * rather than a replacement — including the two that matter most for a package whose
 * model is a documented seam and whose `require` a host installs at runtime.
 */
ArchPresets::strictTypes('RoundlyConsulting\Credits');

/**
 * Two deliberate extension points are exempt: Credit, which `credits.model` invites a
 * host to subclass (pinned by the preset below instead), and CreditsException, the base
 * every credits error extends so a host can catch them uniformly.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Credits', [Credit::class, CreditsException::class]);

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
