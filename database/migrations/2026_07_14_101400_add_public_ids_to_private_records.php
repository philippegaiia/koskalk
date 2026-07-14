<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['workspaces', 'recipes', 'recipe_versions', 'production_batches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('public_id')->nullable()->unique();
            });

            DB::table($tableName)
                ->select('id')
                ->orderBy('id')
                ->eachById(function (object $record) use ($tableName): void {
                    DB::table($tableName)
                        ->where('id', $record->id)
                        ->update(['public_id' => (string) Str::uuid()]);
                });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('public_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['production_batches', 'recipe_versions', 'recipes', 'workspaces'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['public_id']);
                $table->dropColumn('public_id');
            });
        }
    }
};
