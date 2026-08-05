<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgreSqlIntegrityTrigger();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteIntegrityTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity ON production_runs');
            DB::statement('DROP FUNCTION IF EXISTS production_runs_enforce_batch_number_integrity()');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
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
                    IF OLD.batch_number IS NOT NULL THEN
                        RAISE EXCEPTION 'permanently numbered production runs cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.planning_batch_number IS DISTINCT FROM NEW.planning_batch_number THEN
                    RAISE EXCEPTION 'production run planning batch numbers are immutable';
                END IF;

                IF OLD.batch_number IS NOT NULL AND NEW.batch_number IS DISTINCT FROM OLD.batch_number THEN
                    RAISE EXCEPTION 'production run permanent batch numbers are immutable';
                END IF;

                IF OLD.batch_number IS NOT NULL AND (
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
            BEFORE UPDATE OR DELETE ON production_runs
            FOR EACH ROW EXECUTE FUNCTION production_runs_enforce_batch_number_integrity()
        SQL);
    }

    private function createSqliteIntegrityTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_update');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_assignment_metadata_integrity_update');
        DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_delete');
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_runs_number_integrity_update
            BEFORE UPDATE OF planning_batch_number, batch_number ON production_runs
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
