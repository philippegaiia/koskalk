<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alterTable(function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('production_batches')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Cannot restore required batch authors after an author has been deleted.');
        }

        $this->alterTable(function (Blueprint $table): void {
            $table->dropIndex(['workspace_id']);
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable(false)->change()->constrained()->cascadeOnDelete();
        });
    }

    private function alterTable(Closure $alter): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0
            && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1) {
            throw new RuntimeException('Batch provenance requires SQLite outside an active transaction while foreign keys are enabled.');
        }

        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('tbl_name', 'production_batches')->whereNotNull('sql')
                ->where(function ($query): void {
                    $query->where('type', 'trigger')->orWhere(function ($query): void {
                        $query->where('type', 'index')->whereRaw('LOWER(sql) LIKE ?', ['% where %']);
                    });
                })->get(['type', 'name', 'sql'])
            : collect();

        Schema::table('production_batches', $alter);

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
