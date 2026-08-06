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
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aborted_at')->nullable();
            $table->foreignId('aborted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('abort_reason')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->unsignedInteger('actual_output_units')->nullable();
            $table->decimal('actual_output_mass_grams', 20, 9)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->decimal('actual_ingredient_total', 20, 9)->nullable();
            $table->decimal('actual_packaging_total', 20, 9)->nullable();
            $table->decimal('actual_total_cost', 20, 9)->nullable();
            $table->decimal('actual_cost_per_unit', 20, 9)->nullable();
        });

        Schema::create('production_consumption', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->string('subject_name_snapshot');
            $table->decimal('quantity', 20, 9);
            $table->string('unit_snapshot', 24);
            $table->decimal('price_per_unit', 20, 9)->nullable();
            $table->decimal('line_cost', 20, 9)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['production_run_id', 'id']);
            $table->index(['production_requirement_id', 'production_run_id']);
            $table->index(['stock_lot_id', 'production_run_id']);
        });

        Schema::create('production_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_run_id', 'created_at']);
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->foreignId('production_run_id')->nullable()->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_runs
                ADD CONSTRAINT production_runs_output_kind_exclusive_check
                CHECK (
                    actual_output_units IS NULL
                    OR actual_output_mass_grams IS NULL
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE production_consumption
                ADD CONSTRAINT production_consumption_valid_values_check
                CHECK (
                    (
                        kind = 'ingredient'
                        AND unit_snapshot = 'g'
                        AND quantity > 0
                    )
                    OR
                    (
                        kind = 'packaging'
                        AND unit_snapshot = 'unit'
                        AND quantity > 0
                        AND quantity = floor(quantity)
                    )
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createConsumptionValidationTriggers();
            $this->createOutputExclusivityTriggers();

            // Both altered tables are rebuilt by Laravel on SQLite because the
            // blueprints carry foreign-key commands, and a rebuild drops every
            // trigger attached to the table. Recreate the production_runs and
            // stock_lots integrity triggers installed by earlier migrations.
            $this->restoreProductionRunIntegrityTriggers();
            $this->restoreStockLotIntegrityTriggers();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_consumption_valid_values_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_consumption_valid_values_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_output_kind_exclusive_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_output_kind_exclusive_update');
        }

        Schema::dropIfExists('production_consumption');
        Schema::dropIfExists('production_journal_entries');

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropForeign(['production_run_id']);
            $table->dropColumn('production_run_id');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropForeign(['started_by_user_id']);
            $table->dropForeign(['completed_by_user_id']);
            $table->dropForeign(['aborted_by_user_id']);
            $table->dropColumn([
                'started_at',
                'started_by_user_id',
                'completed_at',
                'completed_by_user_id',
                'aborted_at',
                'aborted_by_user_id',
                'abort_reason',
                'manufacture_date',
                'actual_output_units',
                'actual_output_mass_grams',
                'cost_currency',
                'actual_ingredient_total',
                'actual_packaging_total',
                'actual_total_cost',
                'actual_cost_per_unit',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_runs DROP CONSTRAINT IF EXISTS production_runs_output_kind_exclusive_check');
            DB::statement('ALTER TABLE production_consumption DROP CONSTRAINT IF EXISTS production_consumption_valid_values_check');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreProductionRunIntegrityTriggers();
            $this->restoreStockLotIntegrityTriggers();
        }
    }

    private function createConsumptionValidationTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_consumption_valid_values_insert
            BEFORE INSERT ON production_consumption
            WHEN NOT (
                (
                    NEW.kind = 'ingredient'
                    AND NEW.unit_snapshot = 'g'
                    AND NEW.quantity > 0
                )
                OR
                (
                    NEW.kind = 'packaging'
                    AND NEW.unit_snapshot = 'unit'
                    AND NEW.quantity > 0
                    AND NEW.quantity = CAST(NEW.quantity AS INTEGER)
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'production consumption values are invalid');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_consumption_valid_values_update
            BEFORE UPDATE OF kind, quantity, unit_snapshot ON production_consumption
            WHEN NOT (
                (
                    NEW.kind = 'ingredient'
                    AND NEW.unit_snapshot = 'g'
                    AND NEW.quantity > 0
                )
                OR
                (
                    NEW.kind = 'packaging'
                    AND NEW.unit_snapshot = 'unit'
                    AND NEW.quantity > 0
                    AND NEW.quantity = CAST(NEW.quantity AS INTEGER)
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'production consumption values are invalid');
            END
        SQL);
    }

    private function createOutputExclusivityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_output_kind_exclusive_insert
            BEFORE INSERT ON production_runs
            WHEN NEW.actual_output_units IS NOT NULL AND NEW.actual_output_mass_grams IS NOT NULL
            BEGIN
                SELECT RAISE(ABORT, 'production run output kind must be either units or mass, not both');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_output_kind_exclusive_update
            BEFORE UPDATE OF actual_output_units, actual_output_mass_grams ON production_runs
            WHEN NEW.actual_output_units IS NOT NULL AND NEW.actual_output_mass_grams IS NOT NULL
            BEGIN
                SELECT RAISE(ABORT, 'production run output kind must be either units or mass, not both');
            END
        SQL);
    }

    /**
     * Recreate every production_runs trigger installed by the
     * 2026_08_05_120003, 2026_08_05_120004, and 2026_08_06_120000 migrations,
     * in their original definitions, because a SQLite table rebuild drops them.
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
     * Recreate the stock_lots triggers installed by the
     * 2026_08_02_032251 migration, because a SQLite table rebuild drops them.
     */
    private function restoreStockLotIntegrityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_lots_exact_subject_insert
            BEFORE INSERT ON stock_lots
            WHEN NOT (
                (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
            )
            BEGIN
                SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_lots_exact_subject_update
            BEFORE UPDATE OF ingredient_id, packaging_item_id, unit_kind ON stock_lots
            WHEN NOT (
                (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
            )
            BEGIN
                SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
            END
        SQL);
    }
};
