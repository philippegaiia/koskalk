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
        Schema::create('ingredient_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->string('component_inci_name')->nullable();
            $table->decimal('percentage_in_parent', 8, 5);
            $table->unsignedInteger('sort_order')->default(1);
            $table->text('source_notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->index(['ingredient_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_components');
    }
};
