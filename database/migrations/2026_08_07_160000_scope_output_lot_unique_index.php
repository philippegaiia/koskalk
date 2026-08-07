<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope the single-output-lot invariant to production-output lots only.
     * A plain unique index on production_run_id would also block future
     * non-output lots that happen to carry the FK; the invariant is
     * semantically "one output lot per run".
     */
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropUnique('stock_lots_single_output_per_run');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX stock_lots_single_output_per_run
                ON stock_lots (production_run_id)
                WHERE origin = 'production_output'
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX stock_lots_single_output_per_run
                ON stock_lots (production_run_id)
                WHERE origin = 'production_output'
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS stock_lots_single_output_per_run');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS stock_lots_single_output_per_run');
        }

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->unique(['production_run_id'], 'stock_lots_single_output_per_run');
        });
    }
};
