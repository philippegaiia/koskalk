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
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('source_file');
            $table->string('source_key');
            $table->string('source_code_prefix')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_potentially_saponifiable')->default(false);
            $table->boolean('requires_admin_review')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['source_file', 'source_key']);
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
