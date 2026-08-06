<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_task_set_recipe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_task_set_id')->constrained('production_task_sets')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['production_task_set_id', 'recipe_id']);
            $table->index(['recipe_id', 'is_default']);
        });

        DB::table('recipes')
            ->whereNotNull('default_production_task_set_id')
            ->get(['id', 'default_production_task_set_id'])
            ->each(function (object $recipe): void {
                DB::table('production_task_set_recipe')->insert([
                    'production_task_set_id' => $recipe->default_production_task_set_id,
                    'recipe_id' => $recipe->id,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::statement(
            'CREATE UNIQUE INDEX production_task_set_recipe_default_recipe_unique '
            .'ON production_task_set_recipe (recipe_id) WHERE is_default = true'
        );

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['default_production_task_set_id']);
            $table->dropColumn('default_production_task_set_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreignId('default_production_task_set_id')
                ->nullable()
                ->constrained('production_task_sets')
                ->nullOnDelete();
        });

        DB::table('production_task_set_recipe')
            ->where('is_default', true)
            ->get(['recipe_id', 'production_task_set_id'])
            ->each(function (object $link): void {
                DB::table('recipes')
                    ->where('id', $link->recipe_id)
                    ->update(['default_production_task_set_id' => $link->production_task_set_id]);
            });

        Schema::dropIfExists('production_task_set_recipe');
    }
};
