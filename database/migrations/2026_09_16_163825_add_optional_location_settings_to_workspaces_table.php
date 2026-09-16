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
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('uses_production_locations')->default(false);
            $table->boolean('uses_storage_locations')->default(false);
            $table->unsignedInteger('production_daily_limit')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'uses_production_locations',
                'uses_storage_locations',
                'production_daily_limit',
            ]);
        });
    }
};
