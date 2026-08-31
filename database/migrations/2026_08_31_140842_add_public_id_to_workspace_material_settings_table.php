<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
        });

        DB::table('workspace_material_settings')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $record): void {
                DB::table('workspace_material_settings')
                    ->where('id', $record->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_material_settings', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
