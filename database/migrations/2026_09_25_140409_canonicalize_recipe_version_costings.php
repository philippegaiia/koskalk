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
        $this->assertSafeSqliteRebuild();

        $duplicateVersionIds = DB::table('recipe_version_costings')
            ->select('recipe_version_id')
            ->groupBy('recipe_version_id')
            ->havingRaw('COUNT(*) > 1');
        $duplicates = DB::table('recipe_version_costings')
            ->whereIn('recipe_version_id', $duplicateVersionIds)
            ->orderBy('recipe_version_id')->orderBy('id')
            ->get(['id', 'recipe_version_id', 'user_id']);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Canonical costing requires explicit reconciliation of duplicate versions: '.$duplicates->toJson());
        }

        $inconsistent = DB::table('recipe_version_costings as costings')
            ->leftJoin('recipe_versions as versions', 'versions.id', '=', 'costings.recipe_version_id')
            ->leftJoin('recipes', 'recipes.id', '=', 'versions.recipe_id')
            ->select(['costings.id', 'costings.recipe_version_id', 'versions.recipe_id',
                'versions.workspace_id as version_workspace_id', 'recipes.workspace_id as recipe_workspace_id',
                'versions.owner_type as version_owner_type', 'recipes.owner_type as recipe_owner_type',
                'versions.owner_id as version_owner_id', 'recipes.owner_id as recipe_owner_id'])
            ->get()
            ->filter(fn (object $row): bool => $row->recipe_id === null
                || $row->version_workspace_id !== $row->recipe_workspace_id
                || ($row->version_workspace_id === null && (
                    $row->version_owner_type === null
                    || $row->version_owner_id === null
                    || $row->version_owner_type !== $row->recipe_owner_type
                    || $row->version_owner_id !== $row->recipe_owner_id
                )))
            ->values();

        if ($inconsistent->isNotEmpty()) {
            throw new RuntimeException('Canonical costing requires consistent recipe/version ownership: '.$inconsistent->toJson());
        }

        $this->preserveSqliteSchema(function (): void {
            Schema::table('recipe_version_costings', function (Blueprint $table): void {
                $table->dropUnique(['recipe_version_id', 'user_id']);
                $table->dropForeign(['user_id']);
                $table->foreignId('user_id')->nullable()->change()->constrained()->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->unique('recipe_version_id');
            });
        });
    }

    public function down(): void
    {
        $this->assertSafeSqliteRebuild();

        $missingAuthors = DB::table('recipe_version_costings')->whereNull('user_id')->orderBy('id')->pluck('id');

        if ($missingAuthors->isNotEmpty()) {
            throw new RuntimeException('Cannot restore required original authors for costing IDs: '.$missingAuthors->toJson());
        }

        $this->preserveSqliteSchema(function (): void {
            Schema::table('recipe_version_costings', function (Blueprint $table): void {
                $table->dropUnique(['recipe_version_id']);
                $table->dropForeign(['user_id']);
                $table->dropIndex(['updated_by_user_id']);
                $table->dropConstrainedForeignId('updated_by_user_id');
                $table->foreignId('user_id')->nullable(false)->change()->constrained()->cascadeOnDelete();
                $table->unique(['recipe_version_id', 'user_id']);
            });
        });
    }

    /** SQLite cannot disable cascading foreign keys inside an active transaction. */
    private function assertSafeSqliteRebuild(): void
    {
        if (DB::getDriverName() === 'sqlite'
            && DB::transactionLevel() > 0
            && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1) {
            throw new RuntimeException('Canonical costing migration requires SQLite outside an active transaction while foreign keys are enabled.');
        }
    }

    /** Preserve table-local triggers and partial indexes across SQLite table rebuilds. */
    private function preserveSqliteSchema(Closure $alter): void
    {
        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('tbl_name', 'recipe_version_costings')
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
