<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_share_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedSmallInteger('fingerprint_version');
            $table->string('incoming_fingerprint', 64);
            $table->foreignId('ingredient_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('local_fingerprint', 64);
            $table->string('resolution', 24);
            $table->timestamps();
            $table->unique(['workspace_id', 'lineage_key', 'fingerprint_version', 'incoming_fingerprint'], 'ingredient_share_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_share_mappings');
    }
};
