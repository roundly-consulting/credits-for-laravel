<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Credits\Tests\Fixtures\UuidUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
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
    Schema::dropIfExists('credit_locks');
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
        ->and(morphKtColumn('credits', 'creditable_type')['type'])->toBe('character varying(255)')
        // The owner-lock table keys the same owner, so it follows the same axis.
        ->and(morphKtColumn('credit_locks', 'creditable_id')['type'])->toBe($expected);
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

it('refuses to migrate on an unrecognized key type instead of falling back to bigint', function (): void {
    config()->set('credits.key_type', 'nonsense');
    config()->set('credits.primary_key_type', 'bigint');

    // A typo in a host's config must stop the migration, never silently build bigint
    // columns for a uuid/ulid-keyed host.
    expect(function (): void {
        runCreditsMigration();
    })->toThrow(InvalidConfigurationException::class, 'Configuration value [credits.key_type] must be one of [bigint, uuid, ulid] (case-insensitive), [nonsense] given.');
});

/**
 * End to end for a uuid-keyed owner: the ledger and the owner-lock row both key it with
 * `credits.key_type = uuid`. On Postgres a bigint `creditable_id` rejects the uuid outright
 * (SQLSTATE 22P02), which is why the README tells uuid hosts to set the key.
 */
it('holds credits on a uuid-keyed owner when key_type is uuid', function (): void {
    config()->set('credits.key_type', 'uuid');
    config()->set('credits.primary_key_type', 'bigint');
    runCreditsMigration();

    Schema::dropIfExists('uuid_users');
    Schema::create('uuid_users', function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->string('name')->nullable();
        $t->timestamps();
    });

    $owner = UuidUser::query()->create(['name' => 'Ada']);

    $owner->modifyCredits(100);
    $owner->modifyCredits(-30);
    $owner->setCreditsTo(50);

    expect($owner->creditsBalance())->toBe(50)
        ->and($owner->credits()->count())->toBe(3)
        ->and(DB::table('credit_locks')->where('creditable_id', $owner->id)->value('version'))->toBe(4);

    Schema::dropIfExists('uuid_users');
});
