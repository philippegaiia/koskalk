<?php

use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\IngredientFormulaUsageService;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('groups direct usage by recipe and counts unique saved backups', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Lavender Soap',
    ]);
    $versions = collect([1, 2, 3])->map(fn (int $versionNumber): RecipeVersion => RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'version_number' => $versionNumber,
        'is_current' => false,
    ]))->push(RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'version_number' => 4,
        'is_current' => true,
    ]));

    foreach ($versions as $version) {
        RecipeItem::factory()->create([
            'recipe_version_id' => $version->id,
            'recipe_phase_id' => null,
            'ingredient_id' => $ingredient->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
        ]);
    }

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$ingredient]),
    );

    expect($usage[$ingredient->id])->toHaveCount(1)
        ->and($usage[$ingredient->id][0])->toMatchArray([
            'recipe_id' => $recipe->id,
            'name' => $recipe->name,
            'version_count' => 3,
            'url' => route('recipes.edit', $recipe),
        ]);
});

it('reports formulas reached through nested composite ancestors once', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $directParent = Ingredient::factory()->create();
    $nestedParent = Ingredient::factory()->create();
    IngredientComponent::factory()->create([
        'ingredient_id' => $directParent->id,
        'component_ingredient_id' => $source->id,
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $nestedParent->id,
        'component_ingredient_id' => $directParent->id,
    ]);
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Nested Blend Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    foreach ([$directParent, $nestedParent] as $parent) {
        RecipeItem::factory()->create([
            'recipe_version_id' => $version->id,
            'recipe_phase_id' => null,
            'ingredient_id' => $parent->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
        ]);
    }

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$source]));

    expect($usage[$source->id])->toHaveCount(1)
        ->and($usage[$source->id][0]['recipe_id'])->toBe($recipe->id);
});

it('keeps draft-only usage while reporting zero saved backups', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $draft = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_current' => true,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $draft->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage[$ingredient->id])->toHaveCount(1)
        ->and($usage[$ingredient->id][0]['version_count'])->toBe(0);
});

it('omits workspace formula usage from non-owner members', function () {
    $user = User::factory()->create();
    $workspaceOwner = User::factory()->create();
    $workspace = Workspace::factory()->for($workspaceOwner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($user)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Shared Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('omits legacy workspace formula usage from non-owner members', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($user)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
        'name' => 'Owner-only Shared Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('includes formula usage from a workspace owned by the user', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Owned Workspace Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage[$ingredient->id][0])->toMatchArray([
        'recipe_id' => $recipe->id,
        'name' => 'Owned Workspace Formula',
        'version_count' => 1,
    ]);
});

it('omits usage from an inaccessible workspace formula', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('omits usage from an inaccessible workspace-owned formula without a workspace id', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => null,
        'visibility' => Visibility::Workspace,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('resolves costing-only usage to its recipe', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Costed Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$ingredient]),
    );

    expect($usage[$ingredient->id][0]['recipe_id'])->toBe($recipe->id);
});

it('omits formula usage belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherUsersIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $otherUsersRecipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $otherUsersVersion = RecipeVersion::factory()->create([
        'recipe_id' => $otherUsersRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $otherUsersVersion->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $otherUsersIngredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$otherUsersIngredient]),
    );

    expect($usage)->not->toHaveKey($otherUsersIngredient->id);
});

it('omits costing-only formula usage belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $otherUser->id,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('omits costing-only formula usage from an inaccessible workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $workspace->owner_user_id,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage)->not->toHaveKey($ingredient->id);
});

it('returns an empty array for an empty ingredient collection', function () {
    $user = User::factory()->create();

    expect(app(IngredientFormulaUsageService::class)->forIngredients($user, collect()))
        ->toBe([]);
});
