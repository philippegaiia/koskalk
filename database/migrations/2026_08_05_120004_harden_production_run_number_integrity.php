<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropSqliteTriggersBeforeForeignKeyRebuild();
        $this->replaceAssigningUserForeignKey();

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgreSqlPortableConstraints();
            $this->createPostgreSqlIntegrityTrigger();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteValidationTriggers();
            $this->createSqlitePortableIntegrityTriggers();
            $this->createSqliteIntegrityTriggers();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Production run number integrity hardening is forward-only.');
    }

    private function dropSqliteTriggersBeforeForeignKeyRebuild(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach ([
            'production_runs_valid_values_insert',
            'production_runs_valid_values_update',
            'production_run_number_settings_positive_values_insert',
            'production_run_number_settings_positive_values_update',
            'production_runs_batch_number_serial_positive_insert',
            'production_runs_batch_number_serial_positive_update',
            'production_runs_number_identity_insert',
            'production_runs_number_identity_update',
            'production_runs_number_integrity_update',
            'production_runs_number_assignment_metadata_integrity_update',
            'production_runs_number_integrity_delete',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function replaceAssigningUserForeignKey(): void
    {
        $column = 'batch_number_assigned_by_user_id';
        $constraint = 'production_runs_batch_number_assigned_by_user_id_foreign';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE production_runs DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE production_runs ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES users (id) ON DELETE RESTRICT");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $hasForeignKey = collect(DB::select("PRAGMA foreign_key_list('production_runs')"))
                ->contains(fn (object $foreignKey): bool => $foreignKey->from === $column);

            if ($hasForeignKey) {
                Schema::table('production_runs', function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                });
            }

            Schema::table('production_runs', function (Blueprint $table) use ($column): void {
                $table->foreign($column)
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });

            return;
        }

        Schema::table('production_runs', function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
        });
        Schema::table('production_runs', function (Blueprint $table) use ($column): void {
            $table->foreign($column)
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    private function createPostgreSqlPortableConstraints(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'production_run_number_settings_positive_values_check'
                        AND conrelid = 'production_run_number_settings'::regclass
                ) THEN
                    ALTER TABLE production_run_number_settings
                    ADD CONSTRAINT production_run_number_settings_positive_values_check
                    CHECK (
                        next_planning_serial > 0
                        AND next_permanent_serial > 0
                        AND permanent_padding > 0
                    );
                END IF;
            END
            $$
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'production_runs_batch_number_serial_positive_check'
                        AND conrelid = 'production_runs'::regclass
                ) THEN
                    ALTER TABLE production_runs
                    ADD CONSTRAINT production_runs_batch_number_serial_positive_check
                    CHECK (batch_number_serial IS NULL OR batch_number_serial > 0);
                END IF;
            END
            $$
        SQL);
    }

    private function createPostgreSqlIntegrityTrigger(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION production_runs_enforce_batch_number_integrity()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM pg_advisory_xact_lock(OLD.workspace_id);

                    IF OLD.batch_number IS NOT NULL THEN
                        RAISE EXCEPTION 'permanently numbered production runs cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                PERFORM pg_advisory_xact_lock(NEW.workspace_id);

                IF NEW.planning_batch_number IS NOT NULL
                    AND NEW.batch_number IS NOT NULL
                    AND NEW.planning_batch_number = NEW.batch_number THEN
                    RAISE EXCEPTION 'production run planning and permanent batch numbers must differ';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM production_runs
                    WHERE workspace_id = NEW.workspace_id
                        AND batch_number = NEW.planning_batch_number
                        AND id IS DISTINCT FROM NEW.id
                ) OR EXISTS (
                    SELECT 1
                    FROM production_runs
                    WHERE workspace_id = NEW.workspace_id
                        AND planning_batch_number = NEW.batch_number
                        AND id IS DISTINCT FROM NEW.id
                ) THEN
                    RAISE EXCEPTION 'production run planning and permanent batch numbers must be unique across a workspace';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.planning_batch_number IS DISTINCT FROM NEW.planning_batch_number THEN
                    RAISE EXCEPTION 'production run planning batch numbers are immutable';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.batch_number IS NOT NULL AND NEW.batch_number IS DISTINCT FROM OLD.batch_number THEN
                    RAISE EXCEPTION 'production run permanent batch numbers are immutable';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.batch_number IS NOT NULL AND (
                    OLD.batch_number_serial IS DISTINCT FROM NEW.batch_number_serial
                    OR OLD.batch_number_assigned_at IS DISTINCT FROM NEW.batch_number_assigned_at
                    OR OLD.batch_number_assigned_by_user_id IS DISTINCT FROM NEW.batch_number_assigned_by_user_id
                ) THEN
                    RAISE EXCEPTION 'production run batch number assignment metadata is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity ON production_runs');
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_integrity
            BEFORE INSERT OR UPDATE OR DELETE ON production_runs
            FOR EACH ROW EXECUTE FUNCTION production_runs_enforce_batch_number_integrity()
        SQL);
    }

    private function createSqliteValidationTriggers(): void
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

    private function createSqlitePortableIntegrityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_run_number_settings_positive_values_insert
            BEFORE INSERT ON production_run_number_settings
            WHEN NOT (
                NEW.next_planning_serial > 0
                AND NEW.next_permanent_serial > 0
                AND NEW.permanent_padding > 0
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run number settings values must be positive');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_run_number_settings_positive_values_update
            BEFORE UPDATE OF next_planning_serial, next_permanent_serial, permanent_padding ON production_run_number_settings
            WHEN NOT (
                NEW.next_planning_serial > 0
                AND NEW.next_permanent_serial > 0
                AND NEW.permanent_padding > 0
            )
            BEGIN
                SELECT RAISE(ABORT, 'production run number settings values must be positive');
            END
        SQL);
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
    }

    private function createSqliteIntegrityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_identity_insert
            BEFORE INSERT ON production_runs
            WHEN (
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
            BEFORE UPDATE OF workspace_id, planning_batch_number, batch_number ON production_runs
            WHEN (
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
            WHEN OLD.planning_batch_number IS NOT NEW.planning_batch_number
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
    }
};
