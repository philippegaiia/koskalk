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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_runs
                ADD CONSTRAINT production_runs_completion_fields_together_check
                CHECK (
                    (
                        completed_at IS NULL
                        AND completed_by_user_id IS NULL
                        AND manufacture_date IS NULL
                        AND actual_ingredient_total IS NULL
                        AND actual_packaging_total IS NULL
                        AND actual_total_cost IS NULL
                        AND actual_cost_per_unit IS NULL
                    )
                    OR
                    (
                        completed_at IS NOT NULL
                        AND completed_by_user_id IS NOT NULL
                        AND manufacture_date IS NOT NULL
                        AND actual_ingredient_total IS NOT NULL
                        AND actual_packaging_total IS NOT NULL
                        AND actual_total_cost IS NOT NULL
                    )
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE production_runs
                ADD CONSTRAINT production_runs_completion_totals_positive_check
                CHECK (
                    actual_total_cost IS NULL
                    OR (
                        actual_total_cost >= 0
                        AND actual_ingredient_total >= 0
                        AND actual_packaging_total >= 0
                        AND (actual_cost_per_unit IS NULL OR actual_cost_per_unit >= 0)
                    )
                )
            SQL);

            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->unique(['production_run_id'], 'stock_lots_single_output_per_run');
            });

            Schema::table('production_consumption', function (Blueprint $table): void {
                $table->unique(
                    ['production_run_id', 'production_requirement_id', 'stock_lot_id'],
                    'production_consumption_requirement_lot_unique',
                );
            });

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteCompletionTriggers();

            // Native create-unique-index on SQLite: no table rebuild, triggers
            // survive.
            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->unique(['production_run_id'], 'stock_lots_single_output_per_run');
            });

            Schema::table('production_consumption', function (Blueprint $table): void {
                $table->unique(
                    ['production_run_id', 'production_requirement_id', 'stock_lot_id'],
                    'production_consumption_requirement_lot_unique',
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_runs DROP CONSTRAINT IF EXISTS production_runs_completion_fields_together_check');
            DB::statement('ALTER TABLE production_runs DROP CONSTRAINT IF EXISTS production_runs_completion_totals_positive_check');

            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->dropUnique('stock_lots_single_output_per_run');
            });

            Schema::table('production_consumption', function (Blueprint $table): void {
                $table->dropUnique('production_consumption_requirement_lot_unique');
            });

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_runs_completion_fields_together_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_completion_fields_together_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_completion_totals_positive_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_completion_totals_positive_update');

            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->dropUnique('stock_lots_single_output_per_run');
            });

            Schema::table('production_consumption', function (Blueprint $table): void {
                $table->dropUnique('production_consumption_requirement_lot_unique');
            });
        }
    }

    private function createSqliteCompletionTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_completion_fields_together_insert
            BEFORE INSERT ON production_runs
            WHEN NOT (
                (
                    NEW.completed_at IS NULL
                    AND NEW.completed_by_user_id IS NULL
                    AND NEW.manufacture_date IS NULL
                    AND NEW.actual_ingredient_total IS NULL
                    AND NEW.actual_packaging_total IS NULL
                    AND NEW.actual_total_cost IS NULL
                    AND NEW.actual_cost_per_unit IS NULL
                )
                OR
                (
                    NEW.completed_at IS NOT NULL
                    AND NEW.completed_by_user_id IS NOT NULL
                    AND NEW.manufacture_date IS NOT NULL
                    AND NEW.actual_ingredient_total IS NOT NULL
                    AND NEW.actual_packaging_total IS NOT NULL
                    AND NEW.actual_total_cost IS NOT NULL
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'production completion fields must appear together');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_completion_fields_together_update
            BEFORE UPDATE OF completed_at, completed_by_user_id, manufacture_date, actual_ingredient_total, actual_packaging_total, actual_total_cost, actual_cost_per_unit ON production_runs
            WHEN NOT (
                (
                    NEW.completed_at IS NULL
                    AND NEW.completed_by_user_id IS NULL
                    AND NEW.manufacture_date IS NULL
                    AND NEW.actual_ingredient_total IS NULL
                    AND NEW.actual_packaging_total IS NULL
                    AND NEW.actual_total_cost IS NULL
                    AND NEW.actual_cost_per_unit IS NULL
                )
                OR
                (
                    NEW.completed_at IS NOT NULL
                    AND NEW.completed_by_user_id IS NOT NULL
                    AND NEW.manufacture_date IS NOT NULL
                    AND NEW.actual_ingredient_total IS NOT NULL
                    AND NEW.actual_packaging_total IS NOT NULL
                    AND NEW.actual_total_cost IS NOT NULL
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'production completion fields must appear together');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_completion_totals_positive_insert
            BEFORE INSERT ON production_runs
            WHEN NEW.actual_total_cost IS NOT NULL AND NOT (
                NEW.actual_total_cost >= 0
                AND NEW.actual_ingredient_total >= 0
                AND NEW.actual_packaging_total >= 0
                AND (NEW.actual_cost_per_unit IS NULL OR NEW.actual_cost_per_unit >= 0)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production completion totals must be non-negative');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_completion_totals_positive_update
            BEFORE UPDATE OF actual_ingredient_total, actual_packaging_total, actual_total_cost, actual_cost_per_unit ON production_runs
            WHEN NEW.actual_total_cost IS NOT NULL AND NOT (
                NEW.actual_total_cost >= 0
                AND NEW.actual_ingredient_total >= 0
                AND NEW.actual_packaging_total >= 0
                AND (NEW.actual_cost_per_unit IS NULL OR NEW.actual_cost_per_unit >= 0)
            )
            BEGIN
                SELECT RAISE(ABORT, 'production completion totals must be non-negative');
            END
        SQL);
    }
};
