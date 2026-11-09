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
        // The same two key axes as the ledger: the hold's own id follows the credits table's
        // inbound key, the owner morph follows the creditable models' key.
        $keyType = KeyType::fromConfig('credits.primary_key_type');
        $morphKeyType = KeyType::fromConfig('credits.key_type');

        // A reservation against one bucket. It writes no ledger row until it is captured; the
        // capture's ledger row is linked through `credit_id`. Holds live on the ledger's
        // connection, next to the rows and the owner lock they are decided under.
        Schema::create('credit_holds', function (Blueprint $table) use ($keyType, $morphKeyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->morphKey('creditable', $morphKeyType, nullable: false);
            $table->string('bucket');
            $table->bigInteger('amount');
            $table->string('status', 16)->default('held');
            $table->bigInteger('captured_amount')->nullable();

            match ($keyType) {
                KeyType::BigInt => $table->unsignedBigInteger('credit_id')->nullable(),
                KeyType::Uuid => $table->uuid('credit_id')->nullable(),
                KeyType::Ulid => $table->ulid('credit_id')->nullable(),
            };

            $table->string('description')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['creditable_type', 'creditable_id', 'bucket', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }
};
