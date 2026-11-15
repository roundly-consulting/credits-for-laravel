<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        // A hold on an owner with credit parents is one hold per level: the one the call made
        // (its origin, depth 0) and a linked hold on each ancestor (depth 1, 2, …) pointing back
        // at it. Every hold made before this — and every hold of an owner without parents —
        // is its own origin: null, depth 0.
        $keyType = KeyType::fromConfig('credits.primary_key_type');

        Schema::table('credit_holds', function (Blueprint $table) use ($keyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->unsignedBigInteger('origin_hold_id')->nullable(),
                KeyType::Uuid => $table->uuid('origin_hold_id')->nullable(),
                KeyType::Ulid => $table->ulid('origin_hold_id')->nullable(),
            };

            $table->unsignedSmallInteger('chain_depth')->default(0);

            $table->index('origin_hold_id');
        });
    }
};
