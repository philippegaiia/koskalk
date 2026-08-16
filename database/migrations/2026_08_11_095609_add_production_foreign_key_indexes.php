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
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->index('recipe_version_id', 'recipe_items_recipe_version_id_index');
            $table->index('recipe_phase_id', 'recipe_items_recipe_phase_id_index');
        });

        Schema::table('production_formula_lines', function (Blueprint $table): void {
            $table->index('recipe_item_id', 'production_formula_lines_recipe_item_id_index');
        });

        Schema::table('production_requirements', function (Blueprint $table): void {
            $table->index('recipe_item_id', 'production_requirements_recipe_item_id_index');
            $table->index('recipe_version_packaging_item_id', 'production_requirements_recipe_version_packaging_item_id_index');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->index('recipe_id', 'production_runs_recipe_id_index');
            $table->index('recipe_version_id', 'production_runs_recipe_version_id_index');
            $table->index('production_task_set_id', 'production_runs_production_task_set_id_index');
        });

        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->index('production_task_set_id', 'production_tasks_production_task_set_id_index');
            $table->index('production_task_set_item_id', 'production_tasks_production_task_set_item_id_index');
            $table->index('employee_id', 'production_tasks_employee_id_index');
            $table->index('department_id', 'production_tasks_department_id_index');
        });

        Schema::table('production_task_set_items', function (Blueprint $table): void {
            $table->index('production_task_type_id', 'production_task_set_items_production_task_type_id_index');
        });

        Schema::table('production_task_types', function (Blueprint $table): void {
            $table->index('department_id', 'production_task_types_department_id_index');
        });

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->index('recipe_version_id', 'recipe_version_packaging_items_recipe_version_id_index');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->dropIndex('recipe_items_recipe_version_id_index');
            $table->dropIndex('recipe_items_recipe_phase_id_index');
        });

        Schema::table('production_formula_lines', function (Blueprint $table): void {
            $table->dropIndex('production_formula_lines_recipe_item_id_index');
        });

        Schema::table('production_requirements', function (Blueprint $table): void {
            $table->dropIndex('production_requirements_recipe_item_id_index');
            $table->dropIndex('production_requirements_recipe_version_packaging_item_id_index');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropIndex('production_runs_recipe_id_index');
            $table->dropIndex('production_runs_recipe_version_id_index');
            $table->dropIndex('production_runs_production_task_set_id_index');
        });

        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropIndex('production_tasks_production_task_set_id_index');
            $table->dropIndex('production_tasks_production_task_set_item_id_index');
            $table->dropIndex('production_tasks_employee_id_index');
            $table->dropIndex('production_tasks_department_id_index');
        });

        Schema::table('production_task_set_items', function (Blueprint $table): void {
            $table->dropIndex('production_task_set_items_production_task_type_id_index');
        });

        Schema::table('production_task_types', function (Blueprint $table): void {
            $table->dropIndex('production_task_types_department_id_index');
        });

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->dropIndex('recipe_version_packaging_items_recipe_version_id_index');
        });

    }
};
