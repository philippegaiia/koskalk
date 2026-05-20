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
        if (Schema::hasTable('substance_catalog')) {
            return;
        }

        if (Schema::hasTable('regulated_substance_catalog')) {
            Schema::rename('regulated_substance_catalog', 'substance_catalog');

            return;
        }

        Schema::create('substance_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('entity_type', 32)->default('constituent');
            $table->string('inci_name')->nullable();
            $table->string('cas_number')->nullable();
            $table->string('ec_number')->nullable();
            $table->json('synonyms')->nullable();
            $table->foreignId('allergen_id')->nullable()->constrained('allergen_catalog')->nullOnDelete();
            $table->string('source_name')->nullable();
            $table->string('source_url')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['source_name', 'name']);
            $table->index('entity_type');
            $table->index('cas_number');
            $table->index('allergen_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('substance_catalog') && ! Schema::hasTable('regulated_substance_catalog')) {
            Schema::rename('substance_catalog', 'regulated_substance_catalog');

            return;
        }

        Schema::dropIfExists('substance_catalog');
    }
};
