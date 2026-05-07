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
        Schema::create('regulatory_regime_allergens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulatory_regime_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allergen_id')->constrained('allergen_catalog')->cascadeOnDelete();
            $table->string('declaration_label')->nullable();
            $table->decimal('rinse_off_threshold_percent', 8, 5)->default(0.01000);
            $table->decimal('leave_on_threshold_percent', 8, 5)->default(0.00100);
            $table->string('threshold_operator', 32)->default('greater_than_or_equal');
            $table->string('group_key')->nullable();
            $table->string('group_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('source_reference')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['regulatory_regime_id', 'allergen_id']);
            $table->index(['regulatory_regime_id', 'is_active']);
            $table->index(['group_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulatory_regime_allergens');
    }
};
