<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.production_runs_enforce_batch_number_integrity()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM pg_advisory_xact_lock(OLD.workspace_id);

                    IF EXISTS (
                        SELECT 1
                        FROM public.stock_reservations
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
                    FROM public.production_runs
                    WHERE workspace_id = NEW.workspace_id
                        AND batch_number = NEW.planning_batch_number
                        AND id IS DISTINCT FROM NEW.id
                ) OR EXISTS (
                    SELECT 1
                    FROM public.production_runs
                    WHERE workspace_id = NEW.workspace_id
                        AND planning_batch_number = NEW.batch_number
                        AND id IS DISTINCT FROM NEW.id
                ) OR EXISTS (
                    SELECT 1
                    FROM public.production_run_number_issuances
                    WHERE workspace_id = NEW.workspace_id
                        AND batch_number = NEW.planning_batch_number
                ) THEN
                    RAISE EXCEPTION 'production run planning and permanent batch numbers must be unique across a workspace';
                END IF;

                IF NEW.batch_number IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM public.production_run_number_issuances
                    WHERE workspace_id = NEW.workspace_id
                        AND production_run_id = NEW.id
                        AND batch_number = NEW.batch_number
                        AND serial = NEW.batch_number_serial
                        AND issued_by_user_id = NEW.batch_number_assigned_by_user_id
                        AND issued_at = NEW.batch_number_assigned_at
                ) THEN
                    RAISE EXCEPTION 'production run permanent batch number must match its issuance history';
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
            $function$
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Production run number issuance integrity is forward-only.');
    }
};
