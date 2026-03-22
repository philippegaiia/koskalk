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
        Schema::create('allergen_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('source_file');
            $table->string('inci_name');
            $table->string('cas_number')->nullable();
            $table->string('ec_number')->nullable();
            $table->string('common_name_en')->nullable();
            $table->string('common_name_fr')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['source_file', 'inci_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allergen_catalog');
    }
};
