<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_assessment_coverages', function (Blueprint $table) {
            $table->id();
            $table->morphs('coverable');
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('review_state', 24);
            $table->text('insufficient_reason')->nullable();
            $table->boolean('covers_whole_material')->default(false);
            $table->boolean('covers_constituents')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['coverable_type', 'coverable_id']);
        });

        Schema::create('material_assessment_coverage_substance', function (Blueprint $table) {
            $table->foreignId('material_assessment_coverage_id')->constrained(indexName: 'macs_coverage_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id', 'macs_substance_id_foreign')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->unsignedBigInteger('toxicology_profile_id');
            $table->foreign('toxicology_profile_id', 'macs_profile_id_foreign')->references('id')->on('toxicology_profiles')->restrictOnDelete();
            $table->primary(['material_assessment_coverage_id', 'substance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_assessment_coverage_substance');
        Schema::dropIfExists('material_assessment_coverages');
    }
};
