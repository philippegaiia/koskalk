<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->boolean('package_later')->default(false);
        });

        DB::statement("CREATE INDEX stock_lots_packaged_run_index ON stock_lots (production_run_id) WHERE origin = 'packaging_output'");
        $this->updateSubjectGuard(allowBulk: true);
    }

    public function down(): void
    {
        if (DB::table('production_runs')->where('package_later', true)->exists()
            || DB::table('stock_lots')->where('origin', 'packaging_output')->exists()) {
            throw new RuntimeException('Package later records exist. Restore the pre-migration database to roll back.');
        }

        $this->updateSubjectGuard(allowBulk: false);
        DB::statement('DROP INDEX stock_lots_packaged_run_index');

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropColumn('package_later');
        });
    }

    private function updateSubjectGuard(bool $allowBulk): void
    {
        $check = <<<'SQL'
            (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND recipe_id IS NULL AND unit_kind = 'mass')
            OR (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND recipe_id IS NULL AND unit_kind = 'count')
            OR (ingredient_id IS NULL AND packaging_item_id IS NULL AND recipe_id IS NOT NULL AND unit_kind = 'count')
            SQL;

        if ($allowBulk) {
            $check .= " OR (ingredient_id IS NULL AND packaging_item_id IS NULL AND recipe_id IS NOT NULL AND unit_kind = 'mass' AND origin = 'production_output' AND production_run_id IS NOT NULL)";
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_lots DROP CONSTRAINT stock_lots_exact_subject_check');
            DB::statement("ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_exact_subject_check CHECK ({$check})");

            return;
        }

        $check = preg_replace('/\b(ingredient_id|packaging_item_id|recipe_id|unit_kind|origin|production_run_id)\b/', 'NEW.$1', $check);
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF ingredient_id, packaging_item_id, recipe_id, unit_kind, origin, production_run_id'] as $key => $event) {
            DB::statement("DROP TRIGGER IF EXISTS stock_lots_exact_subject_{$key}");
            DB::statement("CREATE TRIGGER stock_lots_exact_subject_{$key} BEFORE {$event} ON stock_lots
                WHEN NOT ({$check}) BEGIN SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject'); END");
        }
    }
};
