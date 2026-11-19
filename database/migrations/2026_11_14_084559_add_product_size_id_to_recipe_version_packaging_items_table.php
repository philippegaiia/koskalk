<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Link each packaging line to a Product size, and give every Product that
     * already has packaging one size holding all of its existing lines.
     *
     * A size still used by a packaging line cannot be deleted on its own; deleting
     * the Product removes its versions, lines and sizes in the same statement.
     */
    public function up(): void
    {
        $this->addProductSizeColumn();

        DB::table('recipes')
            ->whereIn('id', DB::table('recipe_version_packaging_items')
                ->join('recipe_versions', 'recipe_versions.id', '=', 'recipe_version_packaging_items.recipe_version_id')
                ->whereNull('recipe_version_packaging_items.product_size_id')
                ->select('recipe_versions.recipe_id'))
            ->select(['id', 'nominal_content_value', 'nominal_content_unit'])
            ->chunkById(200, function ($recipes): void {
                foreach ($recipes as $recipe) {
                    $productSizeId = DB::table('product_sizes')
                        ->where('recipe_id', $recipe->id)
                        ->orderBy('position')
                        ->orderBy('id')
                        ->value('id')
                        ?? DB::table('product_sizes')->insertGetId([
                            'public_id' => (string) Str::uuid(),
                            'recipe_id' => $recipe->id,
                            'name' => null,
                            'declared_content_value' => $recipe->nominal_content_value,
                            'declared_content_unit' => $recipe->nominal_content_value === null
                                ? null
                                : $recipe->nominal_content_unit,
                            'position' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    DB::table('recipe_version_packaging_items')
                        ->whereNull('product_size_id')
                        ->whereIn('recipe_version_id', DB::table('recipe_versions')
                            ->where('recipe_id', $recipe->id)
                            ->select('id'))
                        ->update(['product_size_id' => $productSizeId]);
                }
            });
    }

    /**
     * Remove the link only. Product sizes stay; their table is dropped by the
     * migration that creates it.
     */
    public function down(): void
    {
        $this->assertSafeSqliteRebuild();

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->dropForeign(['product_size_id']);
        });

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->dropIndex(['product_size_id']);
        });

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->dropColumn('product_size_id');
        });
    }

    /**
     * SQLite adds a nullable referencing column natively; going through the
     * schema builder would rebuild the table and null the production
     * requirement links that point at its rows. Each SQLite step is checked on
     * its own so a retry after a partial run still creates the index.
     */
    private function addProductSizeColumn(): void
    {
        $hasColumn = Schema::hasColumn('recipe_version_packaging_items', 'product_size_id');

        if (DB::getDriverName() === 'sqlite') {
            if (! $hasColumn) {
                DB::statement('ALTER TABLE "recipe_version_packaging_items" ADD COLUMN "product_size_id" integer REFERENCES "product_sizes"("id") ON DELETE NO ACTION');
            }

            DB::statement('CREATE INDEX IF NOT EXISTS "recipe_version_packaging_items_product_size_id_index" ON "recipe_version_packaging_items" ("product_size_id")');

            return;
        }

        if ($hasColumn) {
            return;
        }

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->foreignId('product_size_id')->nullable()->index()->constrained()->noActionOnDelete();
        });
    }

    /** SQLite cannot disable cascading foreign keys inside an active transaction. */
    private function assertSafeSqliteRebuild(): void
    {
        if (DB::getDriverName() === 'sqlite'
            && DB::transactionLevel() > 0
            && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1) {
            throw new RuntimeException('Removing the Product size link requires SQLite outside an active transaction while foreign keys are enabled.');
        }
    }
};
