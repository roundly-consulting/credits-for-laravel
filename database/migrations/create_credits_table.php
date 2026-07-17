<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
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
