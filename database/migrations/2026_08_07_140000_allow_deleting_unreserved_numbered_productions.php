<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow deleting permanently numbered production runs when they have no
     * active stock reservations. Assigning a batch number may be a mistake;
     * only reserved stock must protect a run from deletion.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->relaxPostgreSqlDeleteTrigger();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->relaxSqliteDeleteTrigger();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->restorePostgreSqlDeleteTrigger();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreSqliteDeleteTrigger();
        }
    }

    private function relaxPostgreSqlDeleteTrigger(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION production_runs_enforce_batch_number_integrity()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM pg_advisory_xact_lock(OLD.workspace_id);

                    IF OLD.batch_number IS NOT NULL AND EXISTS (
                        SELECT 1
                        FROM stock_reservations
                        WHERE production_run_id = OLD.id
                            AND status = 'active'
                    ) THEN
                        RAISE EXCEPTION 'reserved production runs cannot be deleted';
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

    private function restorePostgreSqlDeleteTrigger(): void
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

    private function relaxSqliteDeleteTrigger(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_delete');
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_integrity_delete
            BEFORE DELETE ON production_runs
            WHEN OLD.batch_number IS NOT NULL AND EXISTS (
                SELECT 1 FROM stock_reservations
                WHERE production_run_id = OLD.id
                    AND status = 'active'
            )
            BEGIN
                SELECT RAISE(ABORT, 'reserved production runs cannot be deleted');
            END
        SQL);
    }

    private function restoreSqliteDeleteTrigger(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_delete');
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
