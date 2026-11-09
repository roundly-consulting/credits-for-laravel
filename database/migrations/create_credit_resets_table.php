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
        $keyType = KeyType::fromConfig('credits.primary_key_type');
        $morphKeyType = KeyType::fromConfig('credits.key_type');

        // One row per owner, bucket and period that was reset. The primary key is what makes
        // a periodic reset idempotent: a second run in the same period finds the row and
        // leaves the balance alone. Written under the owner lock, in the reset's transaction.
        Schema::create('credit_resets', function (Blueprint $table) use ($keyType, $morphKeyType): void {
            $table->morphKey('creditable', $morphKeyType, nullable: false);
            $table->string('bucket');
            $table->string('period', 64);
            $table->bigInteger('reset_to');

            match ($keyType) {
                KeyType::BigInt => $table->unsignedBigInteger('credit_id')->nullable(),
                KeyType::Uuid => $table->uuid('credit_id')->nullable(),
                KeyType::Ulid => $table->ulid('credit_id')->nullable(),
            };

            $table->timestamp('created_at')->nullable();

            $table->primary(['creditable_type', 'creditable_id', 'bucket', 'period']);
        });
    }
};
