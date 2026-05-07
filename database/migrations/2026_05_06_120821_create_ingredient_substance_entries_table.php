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
        Schema::create('ingredient_substance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substance_id')->constrained('substance_catalog')->cascadeOnDelete();
            $table->decimal('concentration_percent', 8, 5)->nullable();
            $table->string('concentration_source', 32)->default('unknown');
            $table->text('source_notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'substance_id']);
            $table->index(['substance_id', 'concentration_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_substance_entries');
    }
};
