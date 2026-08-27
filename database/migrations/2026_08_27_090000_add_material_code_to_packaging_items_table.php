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
        Schema::table('packaging_items', function (Blueprint $table): void {
            $table->string('material_code', 64)->nullable()->after('name');
            $table->unique(['workspace_id', 'material_code'], 'packaging_items_code_unique');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX packaging_items_code_ci_unique ON packaging_items (workspace_id, LOWER(material_code))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS packaging_items_code_ci_unique');
        }

        Schema::table('packaging_items', function (Blueprint $table): void {
            $table->dropUnique('packaging_items_code_unique');
            $table->dropColumn('material_code');
        });
    }
};
