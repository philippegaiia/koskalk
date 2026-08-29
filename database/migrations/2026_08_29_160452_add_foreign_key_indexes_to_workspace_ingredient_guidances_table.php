<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspace_ingredient_guidances', function (Blueprint $table): void {
            $table->index('ingredient_id', 'workspace_ingredient_guidances_ingredient_id_index');
            $table->index('created_by_user_id', 'workspace_ingredient_guidances_created_by_user_id_index');
            $table->index('updated_by_user_id', 'workspace_ingredient_guidances_updated_by_user_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_ingredient_guidances', function (Blueprint $table): void {
            $table->dropIndex('workspace_ingredient_guidances_ingredient_id_index');
            $table->dropIndex('workspace_ingredient_guidances_created_by_user_id_index');
            $table->dropIndex('workspace_ingredient_guidances_updated_by_user_id_index');
        });
    }
};
