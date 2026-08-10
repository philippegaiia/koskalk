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
            CREATE OR REPLACE FUNCTION public.production_run_number_issuances_enforce_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'production run number issuance history is immutable';
                END IF;

                IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                    OR NEW.batch_number IS DISTINCT FROM OLD.batch_number
                    OR NEW.serial IS DISTINCT FROM OLD.serial
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
                    RAISE EXCEPTION 'production run number issuance history is immutable';
                END IF;

                IF NEW.production_run_id IS DISTINCT FROM OLD.production_run_id THEN
                    IF NOT (
                        OLD.production_run_id IS NOT NULL
                        AND NEW.production_run_id IS NULL
                        AND pg_trigger_depth() > 1
                    ) THEN
                        RAISE EXCEPTION 'production run number issuance links are immutable';
                    END IF;
                END IF;

                IF NEW.issued_by_user_id IS DISTINCT FROM OLD.issued_by_user_id THEN
                    IF NOT (
                        OLD.issued_by_user_id IS NOT NULL
                        AND NEW.issued_by_user_id IS NULL
                        AND pg_trigger_depth() > 1
                    ) THEN
                        RAISE EXCEPTION 'production run number issuer links are immutable';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $function$
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS production_run_number_issuances_immutability ON public.production_run_number_issuances');
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_run_number_issuances_immutability
            BEFORE UPDATE OR DELETE ON public.production_run_number_issuances
            FOR EACH ROW
            EXECUTE FUNCTION public.production_run_number_issuances_enforce_immutability()
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Production run number issuance immutability is forward-only.');
    }
};
