<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dossier_evidence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('dossier_evidence_file_id');
            $table->foreign('dossier_evidence_file_id')->references('id')->on('dossier_evidence_files')->restrictOnDelete();
            $table->string('type', 64);
            $table->unsignedBigInteger('supplier_qualification_id')->nullable();
            $table->foreign('supplier_qualification_id')->references('id')->on('supplier_qualifications')->restrictOnDelete();
            $table->date('issued_on')->nullable();
            $table->date('revision_due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->foreign('replaced_by_id')->references('id')->on('dossier_evidence_items')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
        });

        Schema::create('dossier_evidence_item_recipe', function (Blueprint $table) {
            $table->foreignId('dossier_evidence_item_id')->constrained(indexName: 'deir_item_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->foreign('recipe_id', 'deir_recipe_id_foreign')->references('id')->on('recipes')->restrictOnDelete();
            $table->primary(['dossier_evidence_item_id', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_evidence_item_recipe');
        Schema::dropIfExists('dossier_evidence_items');
        Schema::dropIfExists('dossier_evidence_files');
    }
};
