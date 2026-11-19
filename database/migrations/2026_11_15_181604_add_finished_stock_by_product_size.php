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
        DB::transaction(function (): void {
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->decimal('product_size_content_value_snapshot', 12, 4)->nullable();
                $table->string('product_size_content_unit_snapshot', 16)->nullable();
            });

            $sqlite = DB::getDriverName() === 'sqlite';
            if ($sqlite) {
                DB::statement('ALTER TABLE stock_lots ADD COLUMN product_size_id integer REFERENCES product_sizes(id) ON DELETE RESTRICT');
            }

            Schema::table('stock_lots', function (Blueprint $table) use ($sqlite): void {
                if (! $sqlite) {
                    $table->foreignId('product_size_id')->nullable();
                }
                $table->index(['product_size_id', 'recipe_id'], 'stock_lots_product_size_recipe_index');
                $table->string('product_size_label_snapshot', 160)->nullable();
                $table->decimal('product_size_content_value_snapshot', 12, 4)->nullable();
                $table->string('product_size_content_unit_snapshot', 16)->nullable();
            });

            if ($sqlite) {
                $this->addSqliteSizeGuards();
            } else {
                Schema::table('product_sizes', function (Blueprint $table): void {
                    $table->unique(['id', 'recipe_id'], 'product_sizes_id_recipe_unique');
                });
                Schema::table('stock_lots', function (Blueprint $table): void {
                    $table->foreign(['product_size_id', 'recipe_id'], 'stock_lots_product_size_recipe_foreign')
                        ->references(['id', 'recipe_id'])->on('product_sizes')->restrictOnDelete();
                });
                DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_size_subject_check CHECK (
                    product_size_id IS NULL OR (recipe_id IS NOT NULL AND unit_kind = \'count\')
                )');
            }

            DB::statement(<<<'SQL'
                UPDATE stock_lots
                SET product_size_id = run.product_size_id,
                    product_size_label_snapshot = run.product_size_label_snapshot
                FROM production_runs AS run
                JOIN product_sizes AS size ON size.id = run.product_size_id AND size.recipe_id = run.recipe_id
                WHERE stock_lots.production_run_id = run.id
                    AND stock_lots.workspace_id = run.workspace_id
                    AND stock_lots.recipe_id = run.recipe_id
                    AND stock_lots.origin = 'production_output'
                    AND stock_lots.unit_kind = 'count'
                SQL);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $sqlite = DB::getDriverName() === 'sqlite';
            if ($sqlite) {
                DB::statement('DROP TRIGGER stock_lots_product_size_insert');
                DB::statement('DROP TRIGGER stock_lots_product_size_update');
                DB::statement('DROP TRIGGER product_sizes_recipe_immutable');
            } else {
                DB::statement('ALTER TABLE stock_lots DROP CONSTRAINT stock_lots_size_subject_check');
                Schema::table('stock_lots', function (Blueprint $table): void {
                    $table->dropForeign('stock_lots_product_size_recipe_foreign');
                });
                Schema::table('product_sizes', function (Blueprint $table): void {
                    $table->dropUnique('product_sizes_id_recipe_unique');
                });
            }

            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->dropIndex('stock_lots_product_size_recipe_index');
                $table->dropColumn(['product_size_id', 'product_size_label_snapshot', 'product_size_content_value_snapshot', 'product_size_content_unit_snapshot']);
            });
            Schema::table('production_runs', function (Blueprint $table): void {
                $table->dropColumn(['product_size_content_value_snapshot', 'product_size_content_unit_snapshot']);
            });
        });
    }

    private function addSqliteSizeGuards(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF product_size_id, recipe_id, unit_kind'] as $name => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER stock_lots_product_size_{$name}
                BEFORE {$event} ON stock_lots
                WHEN NEW.product_size_id IS NOT NULL AND (
                    NEW.unit_kind <> 'count' OR NOT EXISTS (
                        SELECT 1 FROM product_sizes WHERE id = NEW.product_size_id AND recipe_id = NEW.recipe_id
                    )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'stock lot size must belong to its product');
                END
                SQL);
        }

        // A size belongs to its original product. Keep this trigger independent
        // of stock_lots so SQLite can rebuild that table during older rollbacks.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_sizes_recipe_immutable
            BEFORE UPDATE OF recipe_id ON product_sizes
            WHEN NEW.recipe_id <> OLD.recipe_id
            BEGIN
                SELECT RAISE(ABORT, 'product size cannot change product');
            END
            SQL);
    }
};
