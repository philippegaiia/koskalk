<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('packaging_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recipe_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_version_packaging_item_id')
                ->nullable()
                ->constrained('recipe_version_packaging_items')
                ->nullOnDelete();
            $table->string('kind', 24);
            $table->decimal('required_mass_grams', 20, 9)->nullable();
            $table->unsignedBigInteger('required_units')->nullable();
            $table->string('subject_name_snapshot');
            $table->string('phase_key_snapshot')->nullable();
            $table->string('phase_name_snapshot')->nullable();
            $table->decimal('percentage_snapshot', 20, 9)->nullable();
            $table->decimal('components_per_unit_snapshot', 20, 9)->nullable();
            $table->string('unit_snapshot', 24);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['production_run_id', 'sort_order']);
            $table->index(['ingredient_id', 'production_run_id']);
            $table->index(['packaging_item_id', 'production_run_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_requirements
                ADD CONSTRAINT production_requirements_exact_subject_check
                CHECK (
                    (
                        kind = 'ingredient'
                        AND ingredient_id IS NOT NULL
                        AND packaging_item_id IS NULL
                        AND required_mass_grams IS NOT NULL
                        AND required_mass_grams > 0
                        AND required_units IS NULL
                        AND recipe_version_packaging_item_id IS NULL
                        AND components_per_unit_snapshot IS NULL
                    )
                    OR
                    (
                        kind = 'packaging'
                        AND ingredient_id IS NULL
                        AND packaging_item_id IS NOT NULL
                        AND required_mass_grams IS NULL
                        AND required_units IS NOT NULL
                        AND required_units > 0
                        AND recipe_item_id IS NULL
                        AND percentage_snapshot IS NULL
                    )
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER production_requirements_exact_subject_insert
                BEFORE INSERT ON production_requirements
                WHEN NOT (
                    (
                        NEW.kind = 'ingredient'
                        AND NEW.ingredient_id IS NOT NULL
                        AND NEW.packaging_item_id IS NULL
                        AND NEW.required_mass_grams IS NOT NULL
                        AND NEW.required_mass_grams > 0
                        AND NEW.required_units IS NULL
                        AND NEW.recipe_version_packaging_item_id IS NULL
                        AND NEW.components_per_unit_snapshot IS NULL
                    )
                    OR
                    (
                        NEW.kind = 'packaging'
                        AND NEW.ingredient_id IS NULL
                        AND NEW.packaging_item_id IS NOT NULL
                        AND NEW.required_mass_grams IS NULL
                        AND NEW.required_units IS NOT NULL
                        AND NEW.required_units > 0
                        AND NEW.required_units = CAST(NEW.required_units AS INTEGER)
                        AND NEW.recipe_item_id IS NULL
                        AND NEW.percentage_snapshot IS NULL
                    )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'production requirement requires one correctly typed subject and quantity');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER production_requirements_exact_subject_update
                BEFORE UPDATE OF kind, ingredient_id, packaging_item_id, required_mass_grams, required_units, recipe_item_id, recipe_version_packaging_item_id, percentage_snapshot, components_per_unit_snapshot ON production_requirements
                WHEN NOT (
                    (
                        NEW.kind = 'ingredient'
                        AND NEW.ingredient_id IS NOT NULL
                        AND NEW.packaging_item_id IS NULL
                        AND NEW.required_mass_grams IS NOT NULL
                        AND NEW.required_mass_grams > 0
                        AND NEW.required_units IS NULL
                        AND NEW.recipe_version_packaging_item_id IS NULL
                        AND NEW.components_per_unit_snapshot IS NULL
                    )
                    OR
                    (
                        NEW.kind = 'packaging'
                        AND NEW.ingredient_id IS NULL
                        AND NEW.packaging_item_id IS NOT NULL
                        AND NEW.required_mass_grams IS NULL
                        AND NEW.required_units IS NOT NULL
                        AND NEW.required_units > 0
                        AND NEW.required_units = CAST(NEW.required_units AS INTEGER)
                        AND NEW.recipe_item_id IS NULL
                        AND NEW.percentage_snapshot IS NULL
                    )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'production requirement requires one correctly typed subject and quantity');
                END
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_requirements');
    }
};
