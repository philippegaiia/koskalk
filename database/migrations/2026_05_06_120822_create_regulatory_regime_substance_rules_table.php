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
        Schema::create('regulatory_regime_substance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulatory_regime_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substance_id')->constrained('substance_catalog')->cascadeOnDelete();
            $table->string('rule_type', 32)->default('watch');
            $table->decimal('rinse_off_max_percent', 8, 5)->nullable();
            $table->decimal('leave_on_max_percent', 8, 5)->nullable();
            $table->string('threshold_operator', 32)->default('less_than_or_equal');
            $table->string('exposure_scope', 32)->default('both');
            $table->string('label_warning_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('source_reference')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['regulatory_regime_id', 'substance_id']);
            $table->index(['regulatory_regime_id', 'is_active', 'rule_type']);
            $table->index('exposure_scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulatory_regime_substance_rules');
    }
};
