<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_flash_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('idempotency_hash', 64);
            $table->char('request_hash', 64);
            $table->boolean('uses_production_locations')->default(false);
            $table->json('production_ids');
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_hash'], 'flash_submission_workspace_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_flash_submissions');
    }
};
