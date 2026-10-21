<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_assessment_coverage_evidence', function (Blueprint $table) {
            $table->foreignId('material_assessment_coverage_id')->constrained(indexName: 'mace_coverage_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('dossier_evidence_item_id');
            $table->foreign('dossier_evidence_item_id', 'mace_item_id_foreign')->references('id')->on('dossier_evidence_items')->restrictOnDelete();
            $table->primary(['material_assessment_coverage_id', 'dossier_evidence_item_id'], 'mace_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_assessment_coverage_evidence');
    }
};
