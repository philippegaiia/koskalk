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
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE stock_lots
                DROP CONSTRAINT IF EXISTS stock_lots_exact_subject_check
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE stock_lots
                ADD CONSTRAINT stock_lots_exact_subject_check
                CHECK (
                    (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND recipe_id IS NULL AND unit_kind = 'mass')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND recipe_id IS NULL AND unit_kind = 'count')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NULL AND recipe_id IS NOT NULL AND unit_kind = 'count')
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreStockLotIntegrityTriggers();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropForeign(['recipe_id']);
            $table->dropColumn('recipe_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE stock_lots
                DROP CONSTRAINT IF EXISTS stock_lots_exact_subject_check
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE stock_lots
                ADD CONSTRAINT stock_lots_exact_subject_check
                CHECK (
                    (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND unit_kind = 'mass')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND unit_kind = 'count')
                )
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreStockLotIntegrityTriggers();
        }
    }

    /**
     * Recreate the stock_lots subject triggers with the product subject
     * allowed. A SQLite table rebuild drops triggers, so the stock_lots
     * triggers installed by 2026_08_02_032251 are recreated here in their
     * updated three-subject form.
     */
    private function restoreStockLotIntegrityTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_lots_exact_subject_insert
            BEFORE INSERT ON stock_lots
            WHEN NOT (
                (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.recipe_id IS NULL AND NEW.unit_kind = 'mass')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.recipe_id IS NULL AND NEW.unit_kind = 'count')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NULL AND NEW.recipe_id IS NOT NULL AND NEW.unit_kind = 'count')
            )
            BEGIN
                SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_lots_exact_subject_update
            BEFORE UPDATE OF ingredient_id, packaging_item_id, recipe_id, unit_kind ON stock_lots
            WHEN NOT (
                (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.recipe_id IS NULL AND NEW.unit_kind = 'mass')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.recipe_id IS NULL AND NEW.unit_kind = 'count')
                OR
                (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NULL AND NEW.recipe_id IS NOT NULL AND NEW.unit_kind = 'count')
            )
            BEGIN
                SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
            END
        SQL);
    }
};
