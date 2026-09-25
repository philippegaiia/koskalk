<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->index('owner_user_id', 'workspaces_owner_user_id_index');
        });
        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->index('ingredient_id', 'ifra_certificates_ingredient_id_index');
        });
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->index('recipe_version_costing_id', 'rv_costing_packaging_costing_id_index');
        });
        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->index('workspace_id', 'production_batch_presets_workspace_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->dropIndex('production_batch_presets_workspace_id_index');
        });
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropIndex('rv_costing_packaging_costing_id_index');
        });
        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->dropIndex('ifra_certificates_ingredient_id_index');
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex('workspaces_owner_user_id_index');
        });
    }
};
