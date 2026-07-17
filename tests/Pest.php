<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Tests\Fixtures\SwappedCreditTestCase;
use RoundlyConsulting\Credits\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Credits\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Credits\Tests\TestCase;

// Explicit paths, not `->in(__DIR__)`: the ModelSwap directory below needs a different
// base case, and a blanket bind would claim it first. ArchTest.php is listed because
// `swappableModelsAreNotFinal` reads the `credits.model` config default and so needs the
// app booted — an arch file is not automatically test-cased.
uses(TestCase::class)->in('ArchTest.php', 'Feature', 'Unit', 'KeyTypes/BigIntKeyTest.php');

// The key-type seam is fixed at migrate time, so each non-default leg needs
// `credits.primary_key_type` set before the providers boot — a base case per key type is
// the only way to reach that window. The bigint leg above rides the default base case
// precisely because it must prove the *unconfigured* install is correct.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');

// The model-swap proofs need `credits.model` pointed at the host subclass BEFORE the
// providers boot, so they run on their own base case in their own directory — Pest binds a
// test case per directory, not per file.
uses(SwappedCreditTestCase::class)->in('ModelSwap');
