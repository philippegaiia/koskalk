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
        Schema::create('ingredient_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('display_name');
            $table->string('display_name_en')->nullable();
            $table->string('display_name_fr')->nullable();
            $table->string('inci_name')->nullable();
            $table->string('soap_inci_naoh_name')->nullable();
            $table->string('soap_inci_koh_name')->nullable();
            $table->string('cas_number')->nullable();
            $table->string('ec_number')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('price_eur', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_manufactured')->default(false);
            $table->string('source_file');
            $table->string('source_key');
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'version']);
            $table->unique(['source_file', 'source_key', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_versions');
    }
};
