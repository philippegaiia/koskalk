<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\IngredientFormulaMutationService;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('counts distinct formulas across current backup and archived versions', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavender');
    $recipe = privateMutationRecipe($user, 'Lavender Soap');

    $currentVersion = privateMutationVersion($user, $recipe, isCurrent: true, versionNumber: 3);
    privateMutationItem($user, $source, $currentVersion);

    $backupVersion = privateMutationVersion($user, $recipe, isCurrent: false, versionNumber: 2);
    privateMutationItem($user, $source, $backupVersion);
    privateMutationCostingItem($user, $source, $backupVersion);

    $archivedRecipe = privateMutationRecipe($user, 'Archived Lavender Soap', archived: true);
    $archivedVersion = privateMutationVersion($user, $archivedRecipe, isCurrent: false);
    privateMutationCostingItem($user, $source, $archivedVersion);

    $impact = app(IngredientFormulaMutationService::class)->impact($user, $source);

    expect($impact['formula_count'])->toBe(2)
        ->and($impact['editable_recipes']->pluck('id')->all())
        ->toEqualCanonicalizing([$recipe->id, $archivedRecipe->id])
        ->and($impact['recipes']->pluck('id')->all())
        ->toEqualCanonicalizing([$recipe->id, $archivedRecipe->id]);
});

it('separates editable visible blocked and inaccessible formulas without leaking inaccessible records', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');

    $editableRecipe = privateMutationRecipe($user, 'Editable Formula');
    privateMutationItem($user, $source, privateMutationVersion($user, $editableRecipe));

    $workspaceOwner = User::factory()->create();
    $workspace = Workspace::factory()->for($workspaceOwner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $visibleBlockedRecipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Visible Blocked Formula',
    ]);
    $visibleBlockedVersion = RecipeVersion::factory()->create([
        'recipe_id' => $visibleBlockedRecipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    RecipeItem::factory()->create([
        'recipe_version_id' => $visibleBlockedVersion->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $source->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $inaccessibleRecipe = privateMutationRecipe($otherUser, 'Secret Formula');
    privateMutationItem($otherUser, $source, privateMutationVersion($otherUser, $inaccessibleRecipe));

    $impact = app(IngredientFormulaMutationService::class)->impact($user, $source);

    expect($impact['formula_count'])->toBe(3)
        ->and($impact['editable_recipes']->pluck('id')->all())->toBe([$editableRecipe->id])
        ->and($impact['blocked_recipes']->pluck('id')->all())->toBe([$visibleBlockedRecipe->id])
        ->and($impact['blocked_recipes']->pluck('name')->all())->toBe(['Visible Blocked Formula'])
        ->and($impact['inaccessible_blocked_count'])->toBe(1)
        ->and($impact['editable_recipes']->contains('id', $inaccessibleRecipe->id))->toBeFalse()
        ->and($impact['blocked_recipes']->contains('id', $inaccessibleRecipe->id))->toBeFalse();
});

it('reuses policy decisions for formulas with the same authorization context', function (): void {
    $singleUser = User::factory()->create();
    $singleWorkspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($singleWorkspace)->for($singleUser)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $singleSource = privateMutationIngredient($singleUser, IngredientCategory::Additive, 'Single Source');
    workspaceMutationRecipeWithItem($singleWorkspace, $singleSource, 'Single Formula');

    $repeatedUser = User::factory()->create();
    $repeatedWorkspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($repeatedWorkspace)->for($repeatedUser)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $repeatedSource = privateMutationIngredient($repeatedUser, IngredientCategory::Additive, 'Repeated Source');

    foreach (range(1, 3) as $formulaNumber) {
        workspaceMutationRecipeWithItem($repeatedWorkspace, $repeatedSource, "Repeated Formula {$formulaNumber}");
    }

    $authorizationQueries = [];
    DB::listen(function ($query) use (&$authorizationQueries): void {
        if (
            str_contains($query->sql, '"workspaces"')
            || str_contains($query->sql, '"workspace_members"')
        ) {
            $authorizationQueries[] = $query->sql;
        }
    });

    $service = app(IngredientFormulaMutationService::class);
    $singleImpact = $service->impact($singleUser, $singleSource);
    $singleContextQueryCount = count($authorizationQueries);
    $repeatedImpact = $service->impact($repeatedUser, $repeatedSource);
    $repeatedContextQueryCount = count($authorizationQueries) - $singleContextQueryCount;

    expect($singleImpact['formula_count'])->toBe(1)
        ->and($repeatedImpact['formula_count'])->toBe(3)
        ->and($repeatedImpact['editable_recipes'])->toBeEmpty()
        ->and($repeatedImpact['blocked_recipes'])->toHaveCount(3)
        ->and($singleContextQueryCount)->toBeGreaterThan(0)
        ->and($repeatedContextQueryCount)->toBeLessThanOrEqual($singleContextQueryCount);
});

it('allows replacements across the aromatic category family', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavender');
    $essentialOil = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavandin Super');
    $fragranceOil = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Lavender Fragrance',
    ]);
    $co2Extract = Ingredient::factory()->create([
        'category' => IngredientCategory::Co2Extract,
        'display_name' => 'Lavender CO2',
    ]);
    $clay = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'White Clay',
    ]);

    $candidateIds = app(IngredientFormulaMutationService::class)
        ->replacementCandidates($user, $source)
        ->pluck('id')
        ->all();

    expect($candidateIds)->toContain($essentialOil->id, $fragranceOil->id, $co2Extract->id)
        ->not->toContain($source->id, $clay->id);
});

it('restricts ordinary replacements to the same category', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Clay, 'Rose Clay');
    $clay = Ingredient::factory()->create(['category' => IngredientCategory::Clay]);
    $additive = Ingredient::factory()->create(['category' => IngredientCategory::Additive]);

    $candidateIds = app(IngredientFormulaMutationService::class)
        ->replacementCandidates($user, $source)
        ->pluck('id')
        ->all();

    expect($candidateIds)->toContain($clay->id)
        ->not->toContain($additive->id);
});

it('requires a soap eligible carrier replacement when the source is used in saponified oils', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::CarrierOil, 'Olive Oil');
    $recipe = privateMutationRecipe($user, 'Soap Formula');
    $version = privateMutationVersion($user, $recipe);
    privateMutationItem($user, $source, $version, phaseSlug: 'saponified_oils');

    $carrierWithoutSap = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
    ]);
    $carrierWithSap = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
    ]);
    IngredientSapProfile::factory()->for($carrierWithSap)->create(['koh_sap_value' => 0.19]);

    $service = app(IngredientFormulaMutationService::class);
    $impact = $service->impact($user, $source);
    $candidateIds = $service->replacementCandidates($user, $source)->pluck('id')->all();

    expect($impact['requires_soap_carrier'])->toBeTrue()
        ->and($candidateIds)->toContain($carrierWithSap->id)
        ->not->toContain($carrierWithoutSap->id);
});

it('allows a non sap carrier replacement outside saponified oils', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::CarrierOil, 'Jojoba Oil');
    $recipe = privateMutationRecipe($user, 'Anhydrous Formula');
    $version = privateMutationVersion($user, $recipe);
    privateMutationItem($user, $source, $version, phaseSlug: 'additives');

    $carrierWithoutSap = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => false,
    ]);
    $nonCarrier = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
    ]);

    $service = app(IngredientFormulaMutationService::class);
    $impact = $service->impact($user, $source);
    $candidateIds = $service->replacementCandidates($user, $source)->pluck('id')->all();

    expect($impact['requires_soap_carrier'])->toBeFalse()
        ->and($candidateIds)->toContain($carrierWithoutSap->id)
        ->not->toContain($nonCarrier->id);
});

it('returns only active accessible replacement candidates', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Source Additive');
    $ownedCandidate = privateMutationIngredient($user, IngredientCategory::Additive, 'Owned Candidate');
    $platformCandidate = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Platform Candidate',
    ]);
    $inactiveCandidate = privateMutationIngredient($user, IngredientCategory::Additive, 'Inactive Candidate', false);
    $inaccessibleCandidate = privateMutationIngredient($otherUser, IngredientCategory::Additive, 'Other Private Candidate');

    $candidateIds = app(IngredientFormulaMutationService::class)
        ->replacementCandidates($user, $source)
        ->pluck('id')
        ->all();

    expect($candidateIds)->toContain($ownedCandidate->id, $platformCandidate->id)
        ->not->toContain($source->id, $inactiveCandidate->id, $inaccessibleCandidate->id);
});

function privateMutationIngredient(
    User $user,
    IngredientCategory $category,
    string $name,
    bool $isActive = true,
): Ingredient {
    return Ingredient::factory()->create([
        'category' => $category,
        'display_name' => $name,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => $isActive,
    ]);
}

function privateMutationRecipe(User $user, string $name, bool $archived = false): Recipe
{
    return Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'created_by' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $name,
        'archived_at' => $archived ? now() : null,
    ]);
}

function privateMutationVersion(
    User $user,
    Recipe $recipe,
    bool $isCurrent = true,
    int $versionNumber = 1,
): RecipeVersion {
    return RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_current' => $isCurrent,
        'version_number' => $versionNumber,
    ]);
}

function privateMutationItem(
    User $user,
    Ingredient $ingredient,
    RecipeVersion $version,
    ?string $phaseSlug = null,
): RecipeItem {
    $phase = $phaseSlug === null ? null : RecipePhase::factory()->create([
        'recipe_version_id' => $version->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'slug' => $phaseSlug,
    ]);

    return RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => $phase?->id,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
}

function privateMutationCostingItem(
    User $user,
    Ingredient $ingredient,
    RecipeVersion $version,
): RecipeVersionCostingItem {
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'currency' => 'EUR',
    ]);

    return RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
    ]);
}

function workspaceMutationRecipeWithItem(
    Workspace $workspace,
    Ingredient $ingredient,
    string $name,
): Recipe {
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => $name,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
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

    return $recipe;
}
