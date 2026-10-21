<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->boolean('is_current')->default(true)->index();
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('default_dermal_absorption_percent', 8, 4);
            $table->decimal('default_oral_bioavailability_percent', 8, 4);
            $table->decimal('mos_threshold', 12, 4);
            $table->decimal('body_weight_kg', 8, 3);
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exposure_presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_type_id');
            $table->foreign('product_type_id')->references('id')->on('product_types')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('amount_per_use_g', 12, 4);
            $table->decimal('uses_per_day', 8, 3);
            $table->decimal('retention_factor', 8, 5);
            $table->boolean('is_rinse_off');
            $table->json('application_sites');
            $table->json('direct_routes');
            $table->json('secondary_routes');
            $table->string('target_population');
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_type_id', 'version']);
            $table->index(['product_type_id', 'is_current']);
        });

        Schema::create('qra2_parameters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ifra_product_category_id');
            $table->foreign('ifra_product_category_id')->references('id')->on('ifra_product_categories')->restrictOnDelete();
            $table->unsignedBigInteger('ifra_amendment_id');
            $table->foreign('ifra_amendment_id')->references('id')->on('ifra_amendments')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('sensitization_assessment_factor', 10, 3);
            $table->decimal('consumer_exposure_level_mg_cm2_day', 12, 6);
            $table->decimal('qra2_factor', 8, 4)->nullable();
            $table->boolean('applies_qra2_factor')->default(false);
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ifra_product_category_id', 'ifra_amendment_id', 'version'], 'qra2_parameters_series_version_unique');
            $table->index(['ifra_product_category_id', 'ifra_amendment_id', 'is_current'], 'qra2_parameters_series_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qra2_parameters');
        Schema::dropIfExists('exposure_presets');
        Schema::dropIfExists('assessment_policies');
    }
};
