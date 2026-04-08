<?php

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('shows saved recipes on the recipes index page', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive Coconut Bar',
        'slug' => 'olive-coconut-bar',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Published Olive Coconut Bar',
        'is_draft' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_draft' => true,
        'version_number' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Olive Coconut Bar')
        ->assertSee('Open draft')
        ->assertSee('Open recipe')
        ->assertSee('Duplicate');
});

it('only shows recipes that belong to the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $visibleRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Visible Formula',
        'slug' => 'visible-formula',
    ]);

    $hiddenRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Hidden Formula',
        'slug' => 'hidden-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $visibleRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $visibleRecipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $hiddenRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => $hiddenRecipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Visible Formula')
        ->assertDontSee('Hidden Formula');
});

it('only resolves accessible workspace ids once while rendering the recipes index', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
    ]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workspace Formula',
        'slug' => 'workspace-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workspace Formula Published',
        'is_draft' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);

    $workspaceQueries = [];

    DB::listen(function ($query) use (&$workspaceQueries): void {
        if (
            str_contains($query->sql, '"workspaces"')
            || str_contains($query->sql, '"workspace_members"')
        ) {
            $workspaceQueries[] = $query->sql;
        }
    });

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful();

    expect($workspaceQueries)->toHaveCount(2);
});

it('uses one ingredient stats query and skips the unused active product families query', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'is_active' => true,
    ]);

    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Lean Formula',
        'slug' => 'lean-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    $ingredientQueries = [];
    $activeProductFamilyQueries = [];

    DB::listen(function ($query) use (&$ingredientQueries, &$activeProductFamilyQueries): void {
        if (str_contains($query->sql, 'from "ingredients"')) {
            $ingredientQueries[] = $query->sql;
        }

        if (
            str_contains($query->sql, 'from "product_families"')
            && str_contains($query->sql, '"is_active" =')
        ) {
            $activeProductFamilyQueries[] = $query->sql;
        }
    });

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful();

    expect($ingredientQueries)->toHaveCount(1)
        ->and($activeProductFamilyQueries)->toHaveCount(0);
});
