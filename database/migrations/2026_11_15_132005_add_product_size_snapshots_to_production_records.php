<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array Tables = ['production_batches', 'production_runs'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('recipe_version_product_sizes', function (Blueprint $table): void {
                $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_size_id')->index()->constrained()->cascadeOnDelete();
                $table->primary(['recipe_version_id', 'product_size_id']);
            });

            $packaged = DB::table('recipe_version_packaging_items')->whereNotNull('product_size_id')
                ->select(['recipe_version_id', 'product_size_id']);
            $costed = DB::table('recipe_version_costing_size_yields as yield')
                ->join('recipe_version_costings as costing', 'costing.id', '=', 'yield.recipe_version_costing_id')
                ->select(['costing.recipe_version_id', 'yield.product_size_id']);
            $authored = DB::table('recipe_versions as version')
                ->join('product_sizes as size', 'size.recipe_id', '=', 'version.recipe_id')
                ->where(function ($query): void {
                    $query->where(fn ($current) => $current->where('version.is_current', true)->whereNull('size.archived_at'))
                        ->orWhere(fn ($saved) => $saved->where('version.is_current', false)
                            ->whereColumn('size.created_at', '<=', DB::raw('COALESCE(version.saved_at, version.created_at)'))
                            ->where(fn ($available) => $available->whereNull('size.archived_at')
                                ->orWhereColumn('size.archived_at', '>', DB::raw('COALESCE(version.saved_at, version.created_at)'))));
                })
                ->select(['version.id', 'size.id']);
            DB::table('recipe_version_product_sizes')
                ->insertUsing(['recipe_version_id', 'product_size_id'], $packaged->union($costed)->union($authored));

            foreach (self::Tables as $tableName) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('ALTER TABLE "'.$tableName.'" ADD COLUMN "product_size_id" integer REFERENCES "product_sizes"("id") ON DELETE SET NULL');
                    DB::statement('CREATE INDEX "'.$tableName.'_product_size_id_index" ON "'.$tableName.'" ("product_size_id")');
                }

                Schema::table($tableName, function (Blueprint $table): void {
                    if (DB::getDriverName() !== 'sqlite') {
                        $table->foreignId('product_size_id')->nullable()->index()->constrained()->nullOnDelete();
                    }
                    $table->string('product_size_label_snapshot', 160)->nullable();
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::dropIfExists('recipe_version_product_sizes');
            foreach (self::Tables as $tableName) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('DROP INDEX "'.$tableName.'_product_size_id_index"');
                    DB::statement('ALTER TABLE "'.$tableName.'" DROP COLUMN "product_size_id"');
                }

                Schema::table($tableName, function (Blueprint $table): void {
                    if (DB::getDriverName() !== 'sqlite') {
                        $table->dropConstrainedForeignId('product_size_id');
                    }
                    $table->dropColumn('product_size_label_snapshot');
                });
            }
        });
    }
};
