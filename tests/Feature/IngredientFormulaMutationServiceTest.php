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
use App\Models\UserIngredientPrice;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\IngredientFormulaMutationService;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

it('replaces an ingredient across current backup archived and costing-only versions without changing formula rows', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavender');
    $replacement = privateMutationIngredient($user, IngredientCategory::Co2Extract, 'Lavender CO2');
    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $replacement->id,
        'price_per_kg' => 84.75,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    $recipe = privateMutationRecipe($user, 'Lavender Soap');
    $currentVersion = privateMutationVersion($user, $recipe, isCurrent: true, versionNumber: 3);
    $currentVersion->update(generatedIngredientListState());
    $currentItem = privateMutationItem($user, $source, $currentVersion, phaseSlug: 'additives');
    $currentItem->update([
        'percentage' => 3.125,
        'weight' => 31.25,
        'position' => 4,
        'note' => 'Add below 40 C',
    ]);
    $currentCostingItem = privateMutationCostingItem($user, $source, $currentVersion);
    $currentCostingItem->update(['price_per_kg' => 12.34]);

    $backupVersion = privateMutationVersion($user, $recipe, isCurrent: false, versionNumber: 2);
    $backupVersion->update(generatedIngredientListState());
    $backupSourceItem = privateMutationItem($user, $source, $backupVersion);
    $backupSourceItem->update(['percentage' => 2.5, 'weight' => 25, 'position' => 2, 'note' => 'Backup note']);
    $existingReplacementItem = privateMutationItem($user, $replacement, $backupVersion);
    $existingReplacementItem->update(['percentage' => 1.5, 'weight' => 15, 'position' => 3]);

    $archivedRecipe = privateMutationRecipe($user, 'Archived Lavender Soap', archived: true);
    $archivedVersion = privateMutationVersion($user, $archivedRecipe, isCurrent: false);
    $archivedVersion->update(generatedIngredientListState());
    $archivedCostingItem = privateMutationCostingItem($user, $source, $archivedVersion);
    $archivedCostingItem->update(['price_per_kg' => 9.99]);

    app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement);

    expect($source->fresh())->toBeNull()
        ->and($replacement->fresh())->not->toBeNull()
        ->and($currentItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $currentItem->fresh()->percentage)->toBe(3.125)
        ->and((float) $currentItem->fresh()->weight)->toBe(31.25)
        ->and($currentItem->fresh()->recipe_phase_id)->not->toBeNull()
        ->and($currentItem->fresh()->position)->toBe(4)
        ->and($currentItem->fresh()->note)->toBe('Add below 40 C')
        ->and($backupSourceItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $backupSourceItem->fresh()->percentage)->toBe(2.5)
        ->and((float) $backupSourceItem->fresh()->weight)->toBe(25.0)
        ->and($backupSourceItem->fresh()->position)->toBe(2)
        ->and($backupSourceItem->fresh()->note)->toBe('Backup note')
        ->and($existingReplacementItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and(RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $backupVersion->id)->where('ingredient_id', $replacement->id)->count())->toBe(2)
        ->and($currentCostingItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $currentCostingItem->fresh()->price_per_kg)->toBe(84.75)
        ->and($archivedCostingItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $archivedCostingItem->fresh()->price_per_kg)->toBe(84.75);

    foreach ([$currentVersion, $backupVersion, $archivedVersion] as $version) {
        expect($version->fresh()->only(array_keys(generatedIngredientListState())))->toBe([
            'final_ingredient_list' => null,
            'final_ingredient_list_basis_hash' => null,
            'final_plain_ingredient_list' => null,
            'final_plain_ingredient_list_basis_hash' => null,
        ]);
    }
});

it('clears costing prices when the user has no remembered replacement price', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Old Additive');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'New Additive');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Costed Formula'));
    $costingItem = privateMutationCostingItem($user, $source, $version);
    $costingItem->update(['price_per_kg' => 22.50]);

    app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement);

    expect($costingItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and($costingItem->fresh()->price_per_kg)->toBeNull();
});

it('uses each costing owners private remembered replacement price', function (): void {
    $formulaOwner = User::factory()->create();
    $otherCostingOwner = User::factory()->create();
    $source = privateMutationIngredient($formulaOwner, IngredientCategory::Additive, 'Old Additive');
    $replacement = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Platform Replacement',
    ]);
    $version = privateMutationVersion($formulaOwner, privateMutationRecipe($formulaOwner, 'Shared Costed Formula'));
    $formulaOwnerCostingItem = privateMutationCostingItem($formulaOwner, $source, $version);
    $otherOwnerCostingItem = privateMutationCostingItem($otherCostingOwner, $source, $version);
    UserIngredientPrice::query()->create([
        'user_id' => $formulaOwner->id,
        'ingredient_id' => $replacement->id,
        'price_per_kg' => 11.25,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);
    UserIngredientPrice::query()->create([
        'user_id' => $otherCostingOwner->id,
        'ingredient_id' => $replacement->id,
        'price_per_kg' => 37.80,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($formulaOwner, $source, $replacement);

    expect($formulaOwnerCostingItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $formulaOwnerCostingItem->fresh()->price_per_kg)->toBe(11.25)
        ->and($otherOwnerCostingItem->fresh()->ingredient_id)->toBe($replacement->id)
        ->and((float) $otherOwnerCostingItem->fresh()->price_per_kg)->toBe(37.8);
});

it('rolls back every change and keeps media when the replacement is incompatible', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Clay, 'Rose Clay');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/rose-clay.webp',
        'icon_image_path' => 'ingredients/icons/rose-clay.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Clay Soap'));
    $version->update(generatedIngredientListState());
    $item = privateMutationItem($user, $source, $version);
    $costingItem = privateMutationCostingItem($user, $source, $version);
    $costingItem->update(['price_per_kg' => 18.5]);

    expect(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement))
        ->toThrow(ValidationException::class);

    expect($source->fresh())->not->toBeNull()
        ->and($item->fresh()->ingredient_id)->toBe($source->id)
        ->and($costingItem->fresh()->ingredient_id)->toBe($source->id)
        ->and((float) $costingItem->fresh()->price_per_kg)->toBe(18.5)
        ->and($version->fresh()->final_ingredient_list)->toBe('Generated INCI')
        ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
});

it('keeps database state and media when ingredient deletion fails before commit', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Undeletable Additive');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/undeletable.webp',
        'icon_image_path' => 'ingredients/icons/undeletable.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Replacement Additive');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Protected Formula'));
    $version->update(generatedIngredientListState());
    $item = privateMutationItem($user, $source, $version);
    $costingItem = privateMutationCostingItem($user, $source, $version);

    Ingredient::deleting(function (Ingredient $deletingIngredient) use ($source): void {
        if ($deletingIngredient->is($source)) {
            throw new RuntimeException('Forced ingredient deletion failure.');
        }
    });

    expect(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement))
        ->toThrow(RuntimeException::class, 'Forced ingredient deletion failure.');

    expect($source->fresh())->not->toBeNull()
        ->and($item->fresh()->ingredient_id)->toBe($source->id)
        ->and($costingItem->fresh()->ingredient_id)->toBe($source->id)
        ->and($version->fresh()->final_ingredient_list)->toBe('Generated INCI')
        ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
});

it('refuses replacement when a visible workspace formula is blocked and reports its name', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Tussah Silk');
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Viewer]);
    $blockedRecipe = workspaceMutationRecipeWithItem($workspace, $source, 'Blocked Workspace Formula');

    $exception = captureMutationValidationException(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement));

    expect($exception->errors()['ingredient'][0])->toContain($blockedRecipe->name)
        ->and($source->fresh())->not->toBeNull()
        ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $source->id)->exists())->toBeTrue();
});

it('refuses replacement for an inaccessible formula without leaking its name', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Tussah Silk');
    $secretRecipe = privateMutationRecipe($otherUser, 'Secret Competitor Formula');
    privateMutationItem($otherUser, $source, privateMutationVersion($otherUser, $secretRecipe));

    $exception = captureMutationValidationException(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement));
    $messages = implode(' ', $exception->errors()['ingredient']);

    expect($messages)->not->toContain($secretRecipe->name)
        ->and($messages)->toContain('1')
        ->and($source->fresh())->not->toBeNull();
});

it('allows a workspace editor to replace an owned ingredient in an editable workspace formula', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Tussah Silk');
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Editor]);
    $recipe = workspaceMutationRecipeWithItem($workspace, $source, 'Editable Workspace Formula');

    app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement);

    expect($source->fresh())->toBeNull()
        ->and(RecipeItem::withoutGlobalScopes()
            ->whereHas('recipeVersion', fn (Builder $query): Builder => $query->withoutGlobalScopes()->whereBelongsTo($recipe))
            ->where('ingredient_id', $replacement->id)
            ->exists())->toBeTrue();
});

it('refuses replacing a platform ingredient or an ingredient owned by another user', function (string $ownership): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = $ownership === 'platform'
        ? Ingredient::factory()->create(['category' => IngredientCategory::Additive])
        : privateMutationIngredient($otherUser, IngredientCategory::Additive, 'Other User Ingredient');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'Replacement');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Formula'));
    $item = privateMutationItem($user, $source, $version);

    expect(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement))
        ->toThrow(ValidationException::class);

    expect($source->fresh())->not->toBeNull()
        ->and($item->fresh()->ingredient_id)->toBe($source->id);
})->with(['platform', 'other user']);

it('revalidates replacement activity accessibility and identity inside the transaction', function (string $invalidReplacement): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Source');
    $replacement = match ($invalidReplacement) {
        'inactive' => privateMutationIngredient($user, IngredientCategory::Additive, 'Inactive', false),
        'inaccessible' => privateMutationIngredient($otherUser, IngredientCategory::Additive, 'Inaccessible'),
        'source' => $source,
    };
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Formula'));
    $item = privateMutationItem($user, $source, $version);

    $exception = captureMutationValidationException(fn () => app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement));

    expect($exception->errors())->toHaveKey('replacementIngredientId')
        ->and($source->fresh())->not->toBeNull()
        ->and($item->fresh()->ingredient_id)->toBe($source->id);
})->with(['inactive', 'inaccessible', 'source']);

it('deletes ingredient media after a successful replacement', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Old Additive');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/old.webp',
        'icon_image_path' => 'ingredients/icons/old.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');
    $replacement = privateMutationIngredient($user, IngredientCategory::Additive, 'New Additive');

    app(IngredientFormulaMutationService::class)
        ->replaceEverywhereAndDelete($user, $source, $replacement);

    expect($source->fresh())->toBeNull()
        ->and(Storage::disk('public')->exists('ingredients/featured-images/old.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/old.webp'))->toBeFalse();
});

it('removes an ingredient from current backup archived and costing-only versions while preserving formulas and unrelated rows', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Old Additive');
    $unrelatedIngredient = privateMutationIngredient($user, IngredientCategory::Additive, 'Kept Additive');

    $recipe = privateMutationRecipe($user, 'Current Formula');
    $currentVersion = privateMutationVersion($user, $recipe, isCurrent: true, versionNumber: 3);
    $currentVersion->update(generatedIngredientListState());
    $currentSourceItem = privateMutationItem($user, $source, $currentVersion);
    $currentUnrelatedItem = privateMutationItem($user, $unrelatedIngredient, $currentVersion);
    $currentSourceCostingItem = privateMutationCostingItem($user, $source, $currentVersion);
    $currentUnrelatedCostingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $currentSourceCostingItem->recipe_version_costing_id,
        'ingredient_id' => $unrelatedIngredient->id,
        'phase_key' => 'main',
        'position' => 2,
    ]);

    $backupVersion = privateMutationVersion($user, $recipe, isCurrent: false, versionNumber: 2);
    $backupVersion->update(generatedIngredientListState());
    $backupSourceItem = privateMutationItem($user, $source, $backupVersion);

    $archivedRecipe = privateMutationRecipe($user, 'Archived Formula', archived: true);
    $archivedVersion = privateMutationVersion($user, $archivedRecipe, isCurrent: false);
    $archivedVersion->update(generatedIngredientListState());
    $archivedSourceCostingItem = privateMutationCostingItem($user, $source, $archivedVersion);

    app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

    expect($source->fresh())->toBeNull()
        ->and($recipe->fresh())->not->toBeNull()
        ->and($archivedRecipe->fresh())->not->toBeNull()
        ->and($currentVersion->fresh())->not->toBeNull()
        ->and($backupVersion->fresh())->not->toBeNull()
        ->and($archivedVersion->fresh())->not->toBeNull()
        ->and($currentSourceItem->fresh())->toBeNull()
        ->and($backupSourceItem->fresh())->toBeNull()
        ->and($currentSourceCostingItem->fresh())->toBeNull()
        ->and($archivedSourceCostingItem->fresh())->toBeNull()
        ->and($currentUnrelatedItem->fresh()?->ingredient_id)->toBe($unrelatedIngredient->id)
        ->and($currentUnrelatedCostingItem->fresh()?->ingredient_id)->toBe($unrelatedIngredient->id);

    foreach ([$currentVersion, $backupVersion, $archivedVersion] as $version) {
        expect($version->fresh()->only(array_keys(generatedIngredientListState())))->toBe([
            'final_ingredient_list' => null,
            'final_ingredient_list_basis_hash' => null,
            'final_plain_ingredient_list' => null,
            'final_plain_ingredient_list_basis_hash' => null,
        ]);
    }
});

it('keeps a formula and version when the removed ingredient was their only row', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Only Additive');
    $recipe = privateMutationRecipe($user, 'Single Row Formula');
    $version = privateMutationVersion($user, $recipe);
    privateMutationItem($user, $source, $version);

    app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

    expect($recipe->fresh())->not->toBeNull()
        ->and($version->fresh())->not->toBeNull()
        ->and(RecipeItem::withoutGlobalScopes()->whereBelongsTo($version, 'recipeVersion')->exists())->toBeFalse();
});

it('refuses removal when a visible workspace formula is blocked and reports its name', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/blocked-removal.webp',
        'icon_image_path' => 'ingredients/icons/blocked-removal.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Viewer]);
    $blockedRecipe = workspaceMutationRecipeWithItem($workspace, $source, 'Blocked Removal Formula');
    $blockedVersion = RecipeVersion::withoutGlobalScopes()
        ->whereBelongsTo($blockedRecipe)
        ->firstOrFail();
    $blockedVersion->update(generatedIngredientListState());
    $costingItem = privateMutationCostingItem($user, $source, $blockedVersion);

    $exception = captureMutationValidationException(fn () => app(IngredientFormulaMutationService::class)
        ->removeEverywhereAndDelete($user, $source));

    expect($exception->errors()['ingredient'][0])->toContain($blockedRecipe->name)
        ->and($source->fresh())->not->toBeNull()
        ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $source->id)->exists())->toBeTrue()
        ->and($costingItem->fresh())->not->toBeNull()
        ->and($blockedVersion->fresh()->only(array_keys(generatedIngredientListState())))->toBe(generatedIngredientListState())
        ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
});

it('refuses removal for an inaccessible formula without leaking its name', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $secretRecipe = privateMutationRecipe($otherUser, 'Secret Removal Formula');
    privateMutationItem($otherUser, $source, privateMutationVersion($otherUser, $secretRecipe));

    $exception = captureMutationValidationException(fn () => app(IngredientFormulaMutationService::class)
        ->removeEverywhereAndDelete($user, $source));
    $messages = implode(' ', $exception->errors()['ingredient']);

    expect($messages)->not->toContain($secretRecipe->name)
        ->and($messages)->toContain('1')
        ->and($source->fresh())->not->toBeNull();
});

it('allows a workspace editor to remove an owned ingredient from an editable workspace formula', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Silk');
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Editor]);
    $recipe = workspaceMutationRecipeWithItem($workspace, $source, 'Editable Removal Formula');

    app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

    expect($source->fresh())->toBeNull()
        ->and($recipe->fresh())->not->toBeNull()
        ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $source->id)->exists())->toBeFalse();
});

it('refuses removing a platform ingredient another users ingredient or a stale ingredient', function (string $ownership): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = match ($ownership) {
        'platform' => Ingredient::factory()->create(['category' => IngredientCategory::Additive]),
        'other user' => privateMutationIngredient($otherUser, IngredientCategory::Additive, 'Other User Ingredient'),
        'stale' => tap(privateMutationIngredient($user, IngredientCategory::Additive, 'Deleted Ingredient'))->delete(),
    };
    $item = null;
    $costingItem = null;
    $version = null;

    if ($ownership !== 'stale') {
        $source->update([
            'featured_image_path' => "ingredients/featured-images/{$ownership}-protected.webp",
            'icon_image_path' => "ingredients/icons/{$ownership}-protected.webp",
        ]);
        Storage::disk('public')->put($source->featured_image_path, 'featured');
        Storage::disk('public')->put($source->icon_image_path, 'icon');
        $version = privateMutationVersion($user, privateMutationRecipe($user, 'Ownership Protected Formula'));
        $version->update(generatedIngredientListState());
        $item = privateMutationItem($user, $source, $version);
        $costingItem = privateMutationCostingItem($user, $source, $version);
    }

    expect(fn () => app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source))
        ->toThrow(ValidationException::class);

    if ($ownership !== 'stale') {
        expect($source->fresh())->not->toBeNull()
            ->and($item?->fresh())->not->toBeNull()
            ->and($costingItem?->fresh())->not->toBeNull()
            ->and($version?->fresh()->only(array_keys(generatedIngredientListState())))->toBe(generatedIngredientListState())
            ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
            ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
    }
})->with(['platform', 'other user', 'stale']);

it('deletes ingredient media after a successful remove everywhere operation', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Old Additive');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/remove-old.webp',
        'icon_image_path' => 'ingredients/icons/remove-old.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');

    app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

    expect($source->fresh())->toBeNull()
        ->and(Storage::disk('public')->exists('ingredients/featured-images/remove-old.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/remove-old.webp'))->toBeFalse();
});

it('waits for the outer transaction to commit before deleting ingredient media', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Nested Transaction Additive');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/nested-transaction.webp',
        'icon_image_path' => 'ingredients/icons/nested-transaction.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');

    DB::transaction(function () use ($user, $source): void {
        app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

        expect($source->fresh())->toBeNull()
            ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
            ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
    });

    expect(Storage::disk('public')->exists($source->featured_image_path))->toBeFalse()
        ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeFalse();
});

it('rolls back remove everywhere changes and retains media when ingredient deletion fails', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Undeletable Additive');
    $source->update([
        'featured_image_path' => 'ingredients/featured-images/remove-undeletable.webp',
        'icon_image_path' => 'ingredients/icons/remove-undeletable.webp',
    ]);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    Storage::disk('public')->put($source->icon_image_path, 'icon');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Protected Removal Formula'));
    $version->update(generatedIngredientListState());
    $item = privateMutationItem($user, $source, $version);
    $costingItem = privateMutationCostingItem($user, $source, $version);

    Ingredient::deleting(function (Ingredient $deletingIngredient) use ($source): void {
        if ($deletingIngredient->is($source)) {
            throw new RuntimeException('Forced remove ingredient deletion failure.');
        }
    });

    expect(fn () => app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source))
        ->toThrow(RuntimeException::class, 'Forced remove ingredient deletion failure.');

    expect($source->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and($costingItem->fresh())->not->toBeNull()
        ->and($version->fresh()->final_ingredient_list)->toBe('Generated INCI')
        ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($source->icon_image_path))->toBeTrue();
});

it('retains ingredient data and media when an outer transaction rolls back the removal', function (): void {
    Storage::fake('public');
    config(['media.disk' => 'public']);

    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::Additive, 'Outer Rollback Additive');
    $source->update(['featured_image_path' => 'ingredients/featured-images/outer-rollback.webp']);
    Storage::disk('public')->put($source->featured_image_path, 'featured');
    $version = privateMutationVersion($user, privateMutationRecipe($user, 'Outer Rollback Formula'));
    $item = privateMutationItem($user, $source, $version);

    expect(fn () => DB::transaction(function () use ($user, $source): void {
        app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($user, $source);

        throw new RuntimeException('Force outer rollback.');
    }))->toThrow(RuntimeException::class, 'Force outer rollback.');

    expect($source->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and(Storage::disk('public')->exists($source->featured_image_path))->toBeTrue();
});

/** @return array<string, string> */
function generatedIngredientListState(): array
{
    return [
        'final_ingredient_list' => 'Generated INCI',
        'final_ingredient_list_basis_hash' => 'inci-hash',
        'final_plain_ingredient_list' => 'Generated plain list',
        'final_plain_ingredient_list_basis_hash' => 'plain-hash',
    ];
}

/** @param  Closure(): void  $callback */
function captureMutationValidationException(Closure $callback): ValidationException
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a validation exception.');
}

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
