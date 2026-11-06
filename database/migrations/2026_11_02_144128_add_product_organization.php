<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['brand_id', 'slug']);
        });
        $this->preserveSqliteSchema('recipes', fn () => Schema::table('recipes', function (Blueprint $table): void {
            $table->foreignId('product_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->index('brand_id');
            $table->index('product_collection_id');
            $table->index(['workspace_id', 'brand_id']);
            $table->index(['workspace_id', 'product_collection_id']);
        }));
        $this->preserveSqliteSchema('production_runs', fn () => Schema::table('production_runs', function (Blueprint $table): void {
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand_name_snapshot')->nullable();
            $table->string('collection_name_snapshot')->nullable();
            $table->index('brand_id');
            $table->index('product_collection_id');
            $table->index(['workspace_id', 'brand_id']);
            $table->index(['workspace_id', 'product_collection_id']);
        }));
    }

    public function down(): void
    {
        $this->preserveSqliteSchema('production_runs', fn () => Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['product_collection_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['product_collection_id']);
            $table->dropIndex(['workspace_id', 'brand_id']);
            $table->dropIndex(['workspace_id', 'product_collection_id']);
            $table->dropColumn(['brand_id', 'product_collection_id', 'brand_name_snapshot', 'collection_name_snapshot']);
        }));
        $this->preserveSqliteSchema('recipes', fn () => Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['product_collection_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['product_collection_id']);
            $table->dropIndex(['workspace_id', 'brand_id']);
            $table->dropIndex(['workspace_id', 'product_collection_id']);
            $table->dropColumn('product_collection_id');
        }));
        Schema::dropIfExists('product_collections');
    }

    /** Preserve table-local triggers and partial indexes across SQLite table rebuilds. */
    private function preserveSqliteSchema(string $table, Closure $alter): void
    {
        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('tbl_name', $table)
                ->whereNotNull('sql')->where(function (Builder $query): void {
                    $query->where('type', 'trigger')->orWhere(function (Builder $query): void {
                        $query->where('type', 'index')->whereRaw('LOWER(sql) LIKE ?', ['% where %']);
                    });
                })->get(['type', 'name', 'sql'])
            : collect();

        $alter();

        foreach ($definitions as $definition) {
            if ($definition->type === 'index') {
                DB::statement('DROP INDEX IF EXISTS "'.str_replace('"', '""', $definition->name).'"');
            }
            if (! DB::table('sqlite_master')->where('type', $definition->type)->where('name', $definition->name)->exists()) {
                DB::unprepared($definition->sql);
            }
        }
    }
};
