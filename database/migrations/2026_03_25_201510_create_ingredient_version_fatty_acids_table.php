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
        Schema::create('ingredient_version_fatty_acids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fatty_acid_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage', 8, 5);
            $table->text('source_notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_version_id', 'fatty_acid_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_version_fatty_acids');
    }
};
