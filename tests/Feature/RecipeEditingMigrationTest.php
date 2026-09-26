<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('preserves existing formula and costing data through the editing schema round trip', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $workspace->owner_user_id,
        'currency' => 'EUR',
    ]);
    $costing->items()->create([
        'ingredient_id' => Ingredient::factory()->create()->id,
        'phase_key' => 'oils',
        'position' => 0,
        'price_per_kg' => '12.3400',
    ]);
    $tables = ['recipes', 'recipe_versions', 'recipe_version_costings', 'recipe_version_costing_items'];
    $snapshot = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => collect((array) $row)->except('edit_revision')->all())->all(),
    ])->all();
    $before = $snapshot();
    $schemaObjects = DB::getDriverName() === 'sqlite'
        ? DB::table('sqlite_master')->whereIn('tbl_name', ['recipes', 'recipe_version_costings'])->whereIn('type', ['index', 'trigger'])->orderBy('name')->get()->toArray()
        : null;
    $migration = require database_path('migrations/2026_09_26_054654_add_recipe_editing_protection.php');

    $migration->down();
    expect(Schema::hasColumn('recipes', 'edit_revision'))->toBeFalse()
        ->and(Schema::hasTable('recipe_edit_leases'))->toBeFalse()
        ->and($snapshot())->toBe($before);
    $migration->up();

    expect($snapshot())->toBe($before)
        ->and((int) DB::table('recipes')->value('edit_revision'))->toBe(0)
        ->and((int) $costing->fresh()->edit_revision)->toBe(0)
        ->and(Schema::hasTable('recipe_edit_takeovers'))->toBeTrue();
    if ($schemaObjects !== null) {
        expect(DB::table('sqlite_master')->whereIn('tbl_name', ['recipes', 'recipe_version_costings'])->whereIn('type', ['index', 'trigger'])->orderBy('name')->get()->toArray())->toEqual($schemaObjects);
    }
});
