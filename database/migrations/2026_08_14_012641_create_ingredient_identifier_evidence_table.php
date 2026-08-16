<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredient_identifier_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_identifier_id')->constrained()->cascadeOnDelete();
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_tier', 32);
            $table->string('confidence', 32);
            $table->string('source_version', 100)->nullable();
            $table->date('source_updated_at')->nullable();
            $table->timestampTz('retrieved_at');
            $table->timestampsTz();

            $table->unique(['ingredient_identifier_id', 'source_url'], 'ingredient_identifier_evidence_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_identifier_evidence');
    }
};
