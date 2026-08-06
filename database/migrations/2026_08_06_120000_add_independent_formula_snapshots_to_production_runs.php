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
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->string('recipe_name_snapshot')->nullable()->after('recipe_version_id');
            $table->unsignedInteger('source_formula_version_number')->nullable()->after('recipe_name_snapshot');
            $table->json('formula_context_snapshot')->nullable()->after('source_formula_version_number');
            $table->timestamp('formula_snapshot_completed_at')->nullable()->after('formula_context_snapshot');

            $table->dropForeign(['recipe_id']);
            $table->dropForeign(['recipe_version_id']);
            $table->foreignId('recipe_id')->nullable()->change();
            $table->foreignId('recipe_version_id')->nullable()->change();
            $table->foreign('recipe_id')->references('id')->on('recipes')->nullOnDelete();
            $table->foreign('recipe_version_id')->references('id')->on('recipe_versions')->nullOnDelete();
        });

        Schema::create('production_formula_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('component', 24);
            $table->string('subject_name_snapshot');
            $table->string('phase_key_snapshot');
            $table->string('phase_name_snapshot');
            $table->decimal('basis_percentage_snapshot', 20, 9);
            $table->decimal('planned_mass_grams', 20, 9);
            $table->text('note_snapshot')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['production_run_id', 'sort_order']);
            $table->index(['ingredient_id', 'production_run_id']);
        });

        Schema::table('production_requirements', function (Blueprint $table): void {
            $table->text('note_snapshot')->nullable()->after('unit_snapshot');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_formula_lines
                ADD CONSTRAINT production_formula_lines_valid_values_check
                CHECK (
                    component IN ('ingredient', 'naoh', 'koh', 'water')
                    AND basis_percentage_snapshot > 0
                    AND planned_mass_grams > 0
                    AND sort_order > 0
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createFormulaLineValidationTriggers();

            // Laravel implements foreign-key and column changes on SQLite by
            // rebuilding the table, which drops every trigger attached to it.
            // Recreate the production_runs integrity triggers installed by the
            // 2026_08_05_120003 and 2026_08_05_120004 migrations so the rebuild
            // does not silently weaken data integrity.
            $this->restoreProductionRunIntegrityTriggers();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_formula_lines_valid_values_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_formula_lines_valid_values_update');
        }

        Schema::dropIfExists('production_formula_lines');

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->dropForeign(['recipe_id']);
                $table->dropForeign(['recipe_version_id']);
                $table->dropColumn(['recipe_name_snapshot', 'source_formula_version_number', 'formula_context_snapshot', 'formula_snapshot_completed_at']);
                $table->foreignId('recipe_id')->nullable(false)->change();
                $table->foreignId('recipe_version_id')->nullable(false)->change();
                $table->foreign('recipe_id')->references('id')->on('recipes')->restrictOnDelete();
                $table->foreign('recipe_version_id')->references('id')->on('recipe_versions')->restrictOnDelete();
            });

            DB::statement('ALTER TABLE production_requirements DROP COLUMN IF EXISTS note_snapshot');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->dropForeign(['recipe_id']);
                $table->dropForeign(['recipe_version_id']);
                $table->dropColumn(['recipe_name_snapshot', 'source_formula_version_number', 'formula_context_snapshot', 'formula_snapshot_completed_at']);
                $table->foreignId('recipe_id')->nullable(false)->change();
                $table->foreignId('recipe_version_id')->nullable(false)->change();
                $table->foreign('recipe_id')->references('id')->on('recipes')->restrictOnDelete();
                $table->foreign('recipe_version_id')->references('id')->on('recipe_versions')->restrictOnDelete();
            });
            $this->restoreProductionRunIntegrityTriggers();
        }

        Schema::table('production_requirements', function (Blueprint $table): void {
            $table->dropColumn('note_snapshot');
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreProductionRequirementIntegrityTriggers();
        }
    }

    private function createFormulaLineValidationTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_formula_lines_valid_values_insert
            BEFORE INSERT ON production_formula_lines
            WHEN NOT (
                NEW.component IN ('ingredient', 'naoh', 'koh', 'water')
                AND NEW.basis_percentage_snapshot > 0
                AND NEW.planned_mass_grams > 0
                AND NEW.sort_order > 0
                AND NEW.sort_order = CAST(NEW.sort_order AS INTEGER)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production formula line values are invalid');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_formula_lines_valid_values_update
            BEFORE UPDATE OF component, basis_percentage_snapshot, planned_mass_grams, sort_order ON production_formula_lines
            WHEN NOT (
                NEW.component IN ('ingredient', 'naoh', 'koh', 'water')
                AND NEW.basis_percentage_snapshot > 0
                AND NEW.planned_mass_grams > 0
                AND NEW.sort_order > 0
                AND NEW.sort_order = CAST(NEW.sort_order AS INTEGER)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production formula line values are invalid');
            END
        SQL);
    }

    /**
     * Recreate every production_runs trigger installed by the
     * 2026_08_05_120003 and 2026_08_05_120004 migrations, in their
     * original definitions, because a SQLite table rebuild drops them.
     */
    private function restoreProductionRunIntegrityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_batch_number_serial_positive_insert
            BEFORE INSERT ON production_runs
            WHEN NEW.batch_number_serial IS NOT NULL AND NEW.batch_number_serial <= 0
            BEGIN
                SELECT RAISE(ABORT, 'production run batch number serial must be positive');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_batch_number_serial_positive_update
            BEFORE UPDATE OF batch_number_serial ON production_runs
            WHEN NEW.batch_number_serial IS NOT NULL AND NEW.batch_number_serial <= 0
            BEGIN
                SELECT RAISE(ABORT, 'production run batch number serial must be positive');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_identity_insert
            BEFORE INSERT ON production_runs
            WHEN (NEW.batch_number IS NULL AND (
                NEW.batch_number_serial IS NOT NULL
                OR NEW.batch_number_assigned_at IS NOT NULL
                OR NEW.batch_number_assigned_by_user_id IS NOT NULL
            )) OR (NEW.batch_number IS NOT NULL AND (
                NEW.batch_number_serial IS NULL
                OR NEW.batch_number_assigned_at IS NULL
                OR NEW.batch_number_assigned_by_user_id IS NULL
            )) OR (
                NEW.planning_batch_number IS NOT NULL
                AND NEW.batch_number IS NOT NULL
                AND NEW.planning_batch_number = NEW.batch_number
            )
            OR EXISTS (
                SELECT 1 FROM production_runs
                WHERE workspace_id = NEW.workspace_id
                    AND batch_number = NEW.planning_batch_number
            )
            OR EXISTS (
                SELECT 1 FROM production_runs
                WHERE workspace_id = NEW.workspace_id
                    AND planning_batch_number = NEW.batch_number
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run planning and permanent batch numbers must be unique across a workspace');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_identity_update
            BEFORE UPDATE OF workspace_id, planning_batch_number, batch_number, batch_number_serial, batch_number_assigned_at, batch_number_assigned_by_user_id ON production_runs
            WHEN (NEW.batch_number IS NULL AND (
                NEW.batch_number_serial IS NOT NULL
                OR NEW.batch_number_assigned_at IS NOT NULL
                OR NEW.batch_number_assigned_by_user_id IS NOT NULL
            )) OR (NEW.batch_number IS NOT NULL AND (
                NEW.batch_number_serial IS NULL
                OR NEW.batch_number_assigned_at IS NULL
                OR NEW.batch_number_assigned_by_user_id IS NULL
            )) OR (
                NEW.planning_batch_number IS NOT NULL
                AND NEW.batch_number IS NOT NULL
                AND NEW.planning_batch_number = NEW.batch_number
            )
            OR EXISTS (
                SELECT 1 FROM production_runs
                WHERE workspace_id = NEW.workspace_id
                    AND batch_number = NEW.planning_batch_number
                    AND id <> NEW.id
            )
            OR EXISTS (
                SELECT 1 FROM production_runs
                WHERE workspace_id = NEW.workspace_id
                    AND planning_batch_number = NEW.batch_number
                    AND id <> NEW.id
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run planning and permanent batch numbers must be unique across a workspace');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_integrity_update
            BEFORE UPDATE OF workspace_id, planning_batch_number, batch_number ON production_runs
            WHEN OLD.workspace_id IS NOT NEW.workspace_id
                OR OLD.planning_batch_number IS NOT NEW.planning_batch_number
                OR (OLD.batch_number IS NOT NULL AND NEW.batch_number IS NOT OLD.batch_number)
            BEGIN
                SELECT RAISE(ABORT, 'production run batch numbers are immutable');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_assignment_metadata_integrity_update
            BEFORE UPDATE OF batch_number_serial, batch_number_assigned_at, batch_number_assigned_by_user_id ON production_runs
            WHEN OLD.batch_number IS NOT NULL AND (
                OLD.batch_number_serial IS NOT NEW.batch_number_serial
                OR OLD.batch_number_assigned_at IS NOT NEW.batch_number_assigned_at
                OR OLD.batch_number_assigned_by_user_id IS NOT NEW.batch_number_assigned_by_user_id
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run batch number assignment metadata is immutable');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_integrity_delete
            BEFORE DELETE ON production_runs
            WHEN OLD.batch_number IS NOT NULL
            BEGIN
                SELECT RAISE(ABORT, 'permanently numbered production runs cannot be deleted');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_valid_values_insert
            BEFORE INSERT ON production_runs
            WHEN NOT (
                NEW.status IN ('draft', 'scheduled', 'reserved', 'in_production', 'completed', 'cancelled', 'aborted')
                AND NEW.source IN ('direct', 'flash')
                AND NEW.basis_kind IN ('oil_mass', 'total_formula_mass')
                AND NEW.basis_input_unit IN ('g', 'kg', 'oz', 'lb')
                AND NEW.basis_quantity_grams > 0
                AND NEW.basis_input_value > 0
                AND NEW.expected_units > 0
                AND NEW.expected_units = CAST(NEW.expected_units AS INTEGER)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run values are invalid');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_valid_values_update
            BEFORE UPDATE OF status, source, basis_kind, basis_quantity_grams, basis_input_value, basis_input_unit, expected_units ON production_runs
            WHEN NOT (
                NEW.status IN ('draft', 'scheduled', 'reserved', 'in_production', 'completed', 'cancelled', 'aborted')
                AND NEW.source IN ('direct', 'flash')
                AND NEW.basis_kind IN ('oil_mass', 'total_formula_mass')
                AND NEW.basis_input_unit IN ('g', 'kg', 'oz', 'lb')
                AND NEW.basis_quantity_grams > 0
                AND NEW.basis_input_value > 0
                AND NEW.expected_units > 0
                AND NEW.expected_units = CAST(NEW.expected_units AS INTEGER)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run values are invalid');
            END
        SQL);
    }

    /**
     * Recreate the production_requirements triggers lost when SQLite rebuilds
     * the table while removing note_snapshot during rollback.
     */
    private function restoreProductionRequirementIntegrityTriggers(): void
    {
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
};
