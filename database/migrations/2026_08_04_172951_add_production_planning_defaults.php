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
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('production_works_on_weekends')->default(false);
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreignId('default_production_task_set_id')
                ->nullable()
                ->constrained('production_task_sets')
                ->nullOnDelete();
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->foreignId('production_task_set_id')
                ->nullable()
                ->constrained('production_task_sets')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->createProductionRunValidationTriggers();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_update');
        }

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropForeign(['production_task_set_id']);
            $table->dropColumn('production_task_set_id');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['default_production_task_set_id']);
            $table->dropColumn('default_production_task_set_id');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('production_works_on_weekends');
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->createProductionRunValidationTriggers();
        }
    }

    private function createProductionRunValidationTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_insert');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_update');
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
};
