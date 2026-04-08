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
        Schema::create('recipe_version_costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('oil_weight_for_costing', total: 12, places: 3)->nullable();
            $table->string('oil_unit_for_costing', 16)->default('g');
            $table->unsignedInteger('units_produced')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['recipe_version_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_version_costings');
    }
};
