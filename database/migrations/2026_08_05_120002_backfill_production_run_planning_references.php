<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $backfill = function (): void {
            $nextSerialByWorkspace = [];

            DB::table('production_runs')
                ->orderBy('workspace_id')
                ->orderBy('id')
                ->chunk(500, function (Collection $runs) use (&$nextSerialByWorkspace): void {
                    foreach ($runs as $run) {
                        $serial = $nextSerialByWorkspace[$run->workspace_id] ?? 1;

                        DB::table('production_runs')->where('id', $run->id)->update([
                            'planning_batch_number' => 'T'.str_pad((string) $serial, 5, '0', STR_PAD_LEFT),
                        ]);

                        $nextSerialByWorkspace[$run->workspace_id] = $serial + 1;
                    }
                });

            foreach ($nextSerialByWorkspace as $workspaceId => $nextSerial) {
                DB::table('production_run_number_settings')->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $settings = DB::table('production_run_number_settings')
                    ->where('workspace_id', $workspaceId)
                    ->first(['next_planning_serial']);

                DB::table('production_run_number_settings')
                    ->where('workspace_id', $workspaceId)
                    ->update([
                        'next_planning_serial' => max((int) $settings->next_planning_serial, $nextSerial),
                        'updated_at' => now(),
                    ]);
            }

            if (DB::table('production_runs')->whereNull('planning_batch_number')->exists()) {
                throw new RuntimeException('Production run planning references must be backfilled before enforcing their presence.');
            }

            if (DB::getDriverName() === 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_insert');
                DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_update');
            }

            Schema::table('production_runs', function (Blueprint $table): void {
                $table->string('planning_batch_number', 32)->nullable(false)->change();
            });

            if (DB::getDriverName() === 'sqlite') {
                $this->createProductionRunValidationTriggers();
            }
        };

        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function () use ($backfill): void {
                DB::statement('LOCK TABLE production_runs IN SHARE ROW EXCLUSIVE MODE');
                $backfill();
            });

            return;
        }

        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() === 0) {
            DB::statement('BEGIN IMMEDIATE TRANSACTION');

            try {
                $backfill();
                DB::statement('COMMIT');
            } catch (Throwable $exception) {
                DB::statement('ROLLBACK');

                throw $exception;
            }

            return;
        }

        DB::transaction($backfill);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_valid_values_update');
        }

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->string('planning_batch_number', 32)->nullable()->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->createProductionRunValidationTriggers();
        }
    }

    private function createProductionRunValidationTriggers(): void
    {
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
