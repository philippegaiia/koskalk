<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('removes deferred collaboration and ambiguous formula privacy schema', function () {
    expect(Schema::hasTable('workspace_invitations'))->toBeFalse()
        ->and(Schema::hasColumn('recipes', 'is_private'))->toBeFalse()
        ->and(Schema::hasColumn('recipes', 'workspace_id'))->toBeTrue()
        ->and(Schema::hasColumn('recipes', 'created_by'))->toBeTrue();
});

it('normalizes a legacy user-owned formula graph into one owner workspace', function () {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => null,
        'created_by' => null,
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => null,
    ]);
    $phase = RecipePhase::factory()->for($version, 'recipeVersion')->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => null,
    ]);
    $item = RecipeItem::factory()->for($version, 'recipeVersion')->for($phase, 'recipePhase')->create([
        'ingredient_id' => Ingredient::factory(),
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => null,
    ]);

    privateMvpTenancyMigration()->up();

    $workspace = Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->sole();

    foreach ([['recipes', $recipe->id], ['recipe_versions', $version->id], ['recipe_phases', $phase->id], ['recipe_items', $item->id]] as [$table, $id]) {
        expect(DB::table($table)->where('id', $id)->first())
            ->owner_type->toBe(OwnerType::Workspace->value)
            ->owner_id->toBe($workspace->id)
            ->workspace_id->toBe($workspace->id);
    }

    expect(DB::table('recipes')->where('id', $recipe->id)->value('created_by'))->toBe($user->id)
        ->and(DB::table('workspace_members')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->value('role'))->toBe('owner');
});

it('fails before mutation when a legacy formula owner has multiple workspaces', function () {
    $user = User::factory()->create();
    Workspace::factory()->count(2)->for($user, 'owner')->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => null,
    ]);

    expect(fn () => privateMvpTenancyMigration()->up())
        ->toThrow(RuntimeException::class, 'multiple workspaces');

    expect(DB::table('recipes')->where('id', $recipe->id)->first())
        ->owner_type->toBe(OwnerType::User->value)
        ->owner_id->toBe($user->id)
        ->workspace_id->toBeNull();
});

function privateMvpTenancyMigration(): Migration
{
    return require database_path('migrations/2026_07_14_104852_normalize_recipe_workspace_tenancy_for_private_mvp.php');
}
