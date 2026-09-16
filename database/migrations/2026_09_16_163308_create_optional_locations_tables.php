<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $triggers = $this->sqliteTriggers();
        $indexes = $this->sqliteIndexes();
        foreach (['production_locations', 'storage_locations'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('normalized_name', 120);
                $table->boolean('is_active')->default(true);
                if ($name === 'production_locations') {
                    $table->unsignedInteger('daily_production_limit')->default(1);
                }
                $table->unique(['workspace_id', 'normalized_name']);
                $table->timestamps();
            });
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE production_runs ADD COLUMN production_location_id INTEGER REFERENCES production_locations(id) ON DELETE SET NULL');
        }
        Schema::table('production_runs', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreignId('production_location_id')->nullable()->constrained()->nullOnDelete();
            }
            $table->index('production_location_id');
            $table->index(['workspace_id', 'production_location_id', 'planned_for'], 'production_location_dates_index');
        });
        Schema::table('recipes', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE recipes ADD COLUMN default_production_location_id INTEGER REFERENCES production_locations(id) ON DELETE SET NULL');
            } else {
                $table->foreignId('default_production_location_id')->nullable()->constrained('production_locations')->nullOnDelete();
            }
            $table->index('default_production_location_id');
        });
        Schema::table('stock_lots', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE stock_lots ADD COLUMN storage_location_id INTEGER REFERENCES storage_locations(id) ON DELETE SET NULL');
            } else {
                $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            }
            $table->index('storage_location_id');
            $table->index(['workspace_id', 'storage_location_id']);
        });
        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->decimal('buffer_quantity', 20, 9)->nullable()->change();
            $table->foreignId('default_storage_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->index('default_storage_location_id');
        });
        $this->restoreSqliteTriggers($triggers);
        $this->restoreSqliteIndexes($indexes);
    }

    public function down(): void
    {
        $triggers = $this->sqliteTriggers();
        $indexes = $this->sqliteIndexes();
        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->dropIndex(['default_storage_location_id']);
            $table->dropConstrainedForeignId('default_storage_location_id');
        });
        DB::table('workspace_material_settings')->whereNull('buffer_quantity')->delete();
        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->decimal('buffer_quantity', 20, 9)->nullable(false)->change();
        });
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'storage_location_id']);
            $table->dropIndex(['storage_location_id']);
            $table->dropConstrainedForeignId('storage_location_id');
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex(['default_production_location_id']);
            $table->dropConstrainedForeignId('default_production_location_id');
        });
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropIndex('production_location_dates_index');
            $table->dropIndex(['production_location_id']);
            $table->dropConstrainedForeignId('production_location_id');
        });
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('production_locations');
        $this->restoreSqliteTriggers($triggers);
        $this->restoreSqliteIndexes($indexes);
    }

    /** @return array<string, string> */
    private function sqliteIndexes(): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        return DB::table('sqlite_master')->where('type', 'index')->whereNotNull('sql')
            ->whereNotIn('name', ['production_location_dates_index', 'stock_lots_workspace_id_storage_location_id_index', 'production_runs_production_location_id_index', 'stock_lots_storage_location_id_index', 'recipes_default_production_location_id_index', 'workspace_material_settings_default_storage_location_id_index'])
            ->whereIn('tbl_name', ['production_runs', 'recipes', 'stock_lots', 'workspace_material_settings'])
            ->pluck('sql', 'name')->all();
    }

    /** @param array<string, string> $indexes */
    private function restoreSqliteIndexes(array $indexes): void
    {
        foreach ($indexes as $name => $sql) {
            DB::statement('DROP INDEX IF EXISTS "'.str_replace('"', '""', $name).'"');
            DB::unprepared($sql);
        }
    }

    /** @return array<string, string> */
    private function sqliteTriggers(): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        return DB::table('sqlite_master')->where('type', 'trigger')
            ->whereIn('tbl_name', ['production_runs', 'recipes', 'stock_lots', 'workspace_material_settings'])
            ->pluck('sql', 'name')->all();
    }

    /** @param array<string, string> $triggers */
    private function restoreSqliteTriggers(array $triggers): void
    {
        foreach ($triggers as $name => $sql) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()) {
                DB::unprepared($sql);
            }
        }
    }
};
