<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Credits carries TWO independent key-type axes, and this file exists to prove they never
 * collide:
 *
 *  - `credits.primary_key_type` (inbound) governs the credits table's own `id` — the key
 *    OTHER packages' morph columns point at.
 *  - `credits.key_type` (outbound) governs the `creditable` morph — the type of the model
 *    that HOLDS the credits.
 *
 * A host may run bigint-keyed credits pointing at uuid-keyed owners, or the reverse; one
 * config key cannot express that, which is why there are two. On the shared bigint default
 * the emitted schema is byte-identical to the raw `id()` + `morphs()` baseline.
 */
if (! function_exists('morphKtColumn')) {
    /**
     * @return array{type: string, type_name: string, nullable: bool|null}
     */
    function morphKtColumn(string $table, string $column): array
    {
        foreach (Schema::getColumns($table) as $c) {
            if ($c['name'] === $column) {
                return ['type' => $c['type'], 'type_name' => $c['type_name'], 'nullable' => $c['nullable']];
            }
        }

        return ['type' => 'MISSING', 'type_name' => 'MISSING', 'nullable' => null];
    }
}

$pgsqlOnly = fn (): bool => DriverMatrix::driver() !== 'pgsql';

function runCreditsMigration(): void
{
    Schema::dropIfExists('credits');
    $migration = require __DIR__.'/../../database/migrations/create_credits_table.php';
    $migration->up();
}

it('emits a byte-identical id and morph column on the shared bigint default', function (): void {
    // Raw baselines: `id()` for the inbound PK, `morphs()` for the required outbound morph.
    Schema::dropIfExists('kt_ref');
    Schema::create('kt_ref', function (Blueprint $t): void {
        $t->id();
        $t->morphs('req');
    });

    expect(morphKtColumn('credits', 'id'))->toBe(morphKtColumn('kt_ref', 'id'))
        ->and(morphKtColumn('credits', 'creditable_id'))->toBe(morphKtColumn('kt_ref', 'req_id'))
        ->and(morphKtColumn('credits', 'creditable_type'))->toBe(morphKtColumn('kt_ref', 'req_type'));

    Schema::dropIfExists('kt_ref');
});

it('renders the creditable morph as a distinct real column type', function (string $keyType, string $expected): void {
    config()->set('credits.key_type', $keyType);
    config()->set('credits.primary_key_type', 'bigint');

    runCreditsMigration();

    expect(morphKtColumn('credits', 'creditable_id')['type'])->toBe($expected)
        ->and(morphKtColumn('credits', 'creditable_type')['type'])->toBe('character varying(255)');
})->with([
    'bigint' => ['bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid'],
    'ulid' => ['ulid', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart — sqlite affinity hides it');

it('keeps the inbound PK and outbound morph axes independent', function (): void {
    // Axis A: bigint credits id, uuid creditable owner.
    config()->set('credits.primary_key_type', 'bigint');
    config()->set('credits.key_type', 'uuid');
    runCreditsMigration();

    expect(morphKtColumn('credits', 'id')['type'])->toBe('bigint')
        ->and(morphKtColumn('credits', 'creditable_id')['type'])->toBe('uuid');

    // Axis B: the exact inverse — uuid credits id, bigint creditable owner. One config key
    // could not express this; two can.
    config()->set('credits.primary_key_type', 'uuid');
    config()->set('credits.key_type', 'bigint');
    runCreditsMigration();

    expect(morphKtColumn('credits', 'id')['type'])->toBe('uuid')
        ->and(morphKtColumn('credits', 'creditable_id')['type'])->toBe('bigint');
})->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart — sqlite affinity hides it');

it('falls back to the bigint schema for an unrecognized outbound key type', function (): void {
    config()->set('credits.key_type', 'nonsense');
    config()->set('credits.primary_key_type', 'bigint');

    runCreditsMigration();

    $expected = DriverMatrix::driver() === 'pgsql' ? 'bigint' : 'integer';

    expect(morphKtColumn('credits', 'creditable_id')['type'])->toBe($expected);
});
