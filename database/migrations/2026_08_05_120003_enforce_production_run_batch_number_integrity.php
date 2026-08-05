<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE production_run_number_settings
                ADD CONSTRAINT production_run_number_settings_positive_values_check
                CHECK (
                    next_planning_serial > 0
                    AND next_permanent_serial > 0
                    AND permanent_padding > 0
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE production_runs
                ADD CONSTRAINT production_runs_batch_number_serial_positive_check
                CHECK (batch_number_serial IS NULL OR batch_number_serial > 0)
            SQL);
            $this->createPostgreSqlIntegrityTrigger();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqlitePortableIntegrityTriggers();
            $this->createSqliteIntegrityTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity ON production_runs');
            DB::statement('DROP FUNCTION IF EXISTS production_runs_enforce_batch_number_integrity()');
            DB::statement('ALTER TABLE production_run_number_settings DROP CONSTRAINT IF EXISTS production_run_number_settings_positive_values_check');
            DB::statement('ALTER TABLE production_runs DROP CONSTRAINT IF EXISTS production_runs_batch_number_serial_positive_check');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS production_run_number_settings_positive_values_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_run_number_settings_positive_values_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_batch_number_serial_positive_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_batch_number_serial_positive_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_identity_insert');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_identity_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_assignment_metadata_integrity_update');
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_delete');
        }
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

                IF TG_OP = 'UPDATE' AND OLD.workspace_id IS DISTINCT FROM NEW.workspace_id THEN
                    RAISE EXCEPTION 'production run workspace is immutable';
                END IF;

                PERFORM pg_advisory_xact_lock(NEW.workspace_id);

                IF (NEW.batch_number IS NULL AND (
                    NEW.batch_number_serial IS NOT NULL
                    OR NEW.batch_number_assigned_at IS NOT NULL
                    OR NEW.batch_number_assigned_by_user_id IS NOT NULL
                )) OR (NEW.batch_number IS NOT NULL AND (
                    NEW.batch_number_serial IS NULL
                    OR NEW.batch_number_assigned_at IS NULL
                    OR NEW.batch_number_assigned_by_user_id IS NULL
                )) THEN
                    RAISE EXCEPTION 'production run permanent batch number audit fields must be issued together';
                END IF;

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
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_update');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_assignment_metadata_integrity_update');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_delete');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_identity_insert');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_identity_update');
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
    }
};
