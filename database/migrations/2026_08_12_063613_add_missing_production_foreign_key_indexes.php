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
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->index('output_ingredient_id', 'production_runs_output_ingredient_id_index');
        });

        Schema::table('production_run_number_issuances', function (Blueprint $table): void {
            $table->index('production_run_id', 'production_run_number_issuances_production_run_id_index');
            $table->index('issued_by_user_id', 'production_run_number_issuances_issued_by_user_id_index');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->index('output_ingredient_id', 'recipes_output_ingredient_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropIndex('production_runs_output_ingredient_id_index');
        });

        Schema::table('production_run_number_issuances', function (Blueprint $table): void {
            $table->dropIndex('production_run_number_issuances_production_run_id_index');
            $table->dropIndex('production_run_number_issuances_issued_by_user_id_index');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex('recipes_output_ingredient_id_index');
        });
    }
};
