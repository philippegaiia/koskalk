<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Label market checks are opt-in, so an implicit write must not select EU.
     * Existing rows keep their stored market. Only PostgreSQL is altered: a
     * SQLite column change rebuilds the table and would drop its triggers.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recipe_versions ALTER COLUMN regulatory_regime SET DEFAULT 'none'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recipe_versions ALTER COLUMN regulatory_regime SET DEFAULT 'eu'");
        }
    }
};
