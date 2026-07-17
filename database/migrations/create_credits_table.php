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
        // The inbound primary key: other packages' polymorphic columns point at this id,
        // and those default to `bigint`. Defaulting anything else here makes credits
        // unrelatable on a strict engine.
        $keyType = KeyType::fromConfig('credits.primary_key_type');

        Schema::create('credits', function (Blueprint $table) use ($keyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->morphs('creditable');
            $table->string('bucket')->default('default')->index();
            $table->bigInteger('amount');
            $table->string('description')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['creditable_type', 'creditable_id', 'bucket']);
        });
    }
};
