<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->uuid('share_lineage_key')->nullable();
            $table->index(['workspace_id', 'share_lineage_key'], 'ingredient_workspace_lineage');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropIndex('ingredient_workspace_lineage');
            $table->dropColumn('share_lineage_key');
        });
    }
};
