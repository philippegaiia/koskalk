<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toxicology_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('noael_mg_kg_bw_day', 20, 9)->nullable();
            $table->string('noael_route', 16)->nullable();
            $table->string('noael_species', 64)->nullable();
            $table->text('noael_study')->nullable();
            $table->decimal('oral_bioavailability_percent', 8, 4)->nullable();
            $table->decimal('ld50_mg_kg', 20, 9)->nullable();
            $table->string('ld50_route', 16)->nullable();
            $table->decimal('dermal_absorption_percent', 8, 4)->nullable();
            $table->decimal('cmax_percent', 12, 6)->nullable();
            $table->string('cmax_origin')->nullable();
            $table->decimal('adi_mg_kg_bw_day', 20, 9)->nullable();
            $table->text('adi_reasoning')->nullable();
            $table->string('ttc_class', 32)->nullable();
            $table->decimal('ttc_mg_kg_bw_day', 20, 9)->nullable();
            $table->text('ttc_reasoning')->nullable();
            $table->decimal('nesil_ug_cm2', 20, 6)->nullable();
            $table->text('skin_irritation_note')->nullable();
            $table->text('eye_irritation_note')->nullable();
            $table->text('sensitisation_note')->nullable();
            $table->text('phototoxicity_note')->nullable();
            $table->text('cmr_note')->nullable();
            $table->text('clp_classification')->nullable();
            $table->json('references');
            $table->json('value_references');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['substance_id', 'version']);
            $table->index(['substance_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toxicology_profiles');
    }
};
