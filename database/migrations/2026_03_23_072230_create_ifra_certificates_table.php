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
        Schema::create('ifra_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_version_id')->constrained()->cascadeOnDelete();
            $table->string('certificate_name');
            $table->string('document_name')->nullable();
            $table->string('document_path')->nullable();
            $table->string('issuer')->nullable();
            $table->string('reference_code')->nullable();
            $table->string('ifra_amendment')->nullable();
            $table->date('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->boolean('is_current')->default(true);
            $table->text('source_notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifra_certificates');
    }
};
