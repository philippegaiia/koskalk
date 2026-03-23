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
        Schema::create('ingredient_sap_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_version_id')->constrained()->cascadeOnDelete();
            $table->decimal('naoh_sap_value', 10, 6)->nullable();
            $table->decimal('koh_sap_value', 10, 6)->nullable();
            $table->json('fatty_acid_profile')->nullable();
            $table->json('soap_quality_profile')->nullable();
            $table->text('source_notes')->nullable();
            $table->timestamps();

            $table->unique('ingredient_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_sap_profiles');
    }
};
