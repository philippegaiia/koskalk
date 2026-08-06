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
        Schema::create('production_batch_preset_recipe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_batch_preset_id')
                ->constrained('production_batch_presets')
                ->cascadeOnDelete();
            $table->foreignId('recipe_id')
                ->constrained('recipes')
                ->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['production_batch_preset_id', 'recipe_id']);
            $table->index(['recipe_id', 'is_default']);
        });

        DB::table('production_batch_presets')
            ->get(['id', 'recipe_id', 'is_default'])
            ->each(function (object $preset): void {
                DB::table('production_batch_preset_recipe')->insert([
                    'production_batch_preset_id' => $preset->id,
                    'recipe_id' => $preset->recipe_id,
                    'is_default' => (bool) $preset->is_default,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        $defaultIndex = 'production_batch_preset_recipe_default_recipe_unique';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX {$defaultIndex} ON production_batch_preset_recipe (recipe_id) WHERE is_default = TRUE",
            );
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$defaultIndex} ON production_batch_preset_recipe (recipe_id) WHERE is_default = 1",
            );
        }

        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->dropIndex('production_batch_presets_one_default_per_recipe');
            $table->dropIndex('production_batch_presets_workspace_id_recipe_id_is_active_index');
            $table->dropIndex('production_batch_presets_recipe_id_is_default_index');
            $table->dropForeign(['recipe_id']);
            $table->dropColumn(['recipe_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
        });

        DB::table('production_batch_preset_recipe')
            ->where('is_default', true)
            ->get(['production_batch_preset_id', 'recipe_id'])
            ->each(function (object $assignment): void {
                DB::table('production_batch_presets')
                    ->where('id', $assignment->production_batch_preset_id)
                    ->update([
                        'recipe_id' => $assignment->recipe_id,
                        'is_default' => true,
                    ]);
            });

        DB::table('production_batch_preset_recipe')
            ->where('is_default', false)
            ->get(['production_batch_preset_id', 'recipe_id'])
            ->each(function (object $assignment): void {
                DB::table('production_batch_presets')
                    ->where('id', $assignment->production_batch_preset_id)
                    ->whereNull('recipe_id')
                    ->update(['recipe_id' => $assignment->recipe_id]);
            });

        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->index(['workspace_id', 'recipe_id', 'is_active']);
            $table->index(['recipe_id', 'is_default']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX production_batch_presets_one_default_per_recipe ON production_batch_presets (recipe_id) WHERE is_default = TRUE');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX production_batch_presets_one_default_per_recipe ON production_batch_presets (recipe_id) WHERE is_default = 1');
        }

        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS production_batch_preset_recipe_default_recipe_unique');
        }

        Schema::dropIfExists('production_batch_preset_recipe');
    }
};
