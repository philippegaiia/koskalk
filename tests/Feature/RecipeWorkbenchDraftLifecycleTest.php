<?php

use App\Enums\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaStorage;
use App\Services\RecipeContentPersistenceService;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeVersionDeletionService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('publishes the current draft and opens a fresh draft when saving as a new version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Working Draft',
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $newDraft = $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published Formula',
        'water_value' => 33,
    ]), $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();

    expect($publishedVersion->name)->toBe('Published Formula')
        ->and($publishedVersion->saved_at)->not->toBeNull()
        ->and($publishedVersion->version_number)->toBe(1)
        ->and($newDraft->is_current)->toBeTrue()
        ->and($newDraft->saved_at)->toBeNull()
        ->and($newDraft->version_number)->toBe(2)
        ->and($newDraft->name)->toBe('Published Formula')
        ->and($recipe->fresh()->name)->toBe('Published Formula');
});

it('copies the visible instructions to the published snapshot and new current version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount($recipe);
    $component->data['manufacturing_instructions'] = '<p>Visible publish procedure.</p>';

    $result = $component->publish(
        recipeWorkbenchLifecyclePayload($oil),
        $service,
        app(RecipeContentUpdater::class),
    );

    $versions = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('version_number')
        ->get();
    $remountedComponent = app(RecipeWorkbench::class);
    $remountedComponent->mount($recipe->fresh());

    expect($result['ok'])->toBeTrue()
        ->and($versions)->toHaveCount(2)
        ->and($versions->first()->is_current)->toBeFalse()
        ->and($versions->first()->manufacturing_instructions)->toBe('<p>Visible publish procedure.</p>')
        ->and($versions->last()->is_current)->toBeTrue()
        ->and($versions->last()->manufacturing_instructions)->toBe('<p>Visible publish procedure.</p>')
        ->and($recipe->fresh()->manufacturing_instructions)->toBe('<p>Visible publish procedure.</p>')
        ->and($remountedComponent->data['manufacturing_instructions'])->toBeArray()
        ->and(json_encode($remountedComponent->data['manufacturing_instructions'], JSON_THROW_ON_ERROR))
        ->toContain('Visible publish procedure.');
});

it('round trips saved instructions when duplicating a recipe', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $sourceVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Source Formula',
        'manufacturing_instructions' => '<p>Duplicate this procedure.</p>',
    ]));
    $sourceRecipe = Recipe::withoutGlobalScopes()->findOrFail($sourceVersion->recipe_id);

    $duplicateVersion = $service->duplicateRecipe($user, $sourceRecipe);
    $duplicateRecipe = Recipe::withoutGlobalScopes()->findOrFail($duplicateVersion->recipe_id);

    expect($duplicateRecipe->id)->not->toBe($sourceRecipe->id)
        ->and($duplicateRecipe->manufacturing_instructions)->toBe('<p>Duplicate this procedure.</p>')
        ->and($duplicateVersion->manufacturing_instructions)->toBe('<p>Duplicate this procedure.</p>');
});

it('does not mutate published instructions when current instructions are saved later', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $payload = recipeWorkbenchLifecyclePayload($oil, [
        'manufacturing_instructions' => '<p>Original procedure</p>',
    ]);
    $draftVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish(
        $user,
        $soapFamily,
        $payload,
        $recipe,
    );

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount($recipe);
    $component->data['manufacturing_instructions'] = '<p>Revised procedure</p>';
    $contentResult = $component->saveRecipeContent(app(RecipeContentPersistenceService::class));

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    expect($contentResult['ok'])->toBeTrue()
        ->and($publishedVersion->fresh()->manufacturing_instructions)->toBe('<p>Original procedure</p>')
        ->and($currentVersion->fresh()->manufacturing_instructions)->toBe('<p>Revised procedure</p>');
});

it('does not save through a mounted component after the auth session is gone', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->save(
        recipeWorkbenchLifecyclePayload($oil, [
            'name' => 'Fallback Draft',
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('signed in')
        ->and(Recipe::withoutGlobalScopes()->where('name', 'Fallback Draft')->exists())->toBeFalse();
});

it('returns a validation response instead of crashing when saving NaOH soap with negative superfat', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->save(
        recipeWorkbenchLifecyclePayload($oil, [
            'name' => 'Invalid NaOH Negative Superfat',
            'lye_type' => 'naoh',
            'superfat' => -2,
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBe('Negative superfat is only supported for liquid or high-KOH soap workflows.')
        ->and(Recipe::withoutGlobalScopes()->where('name', 'Invalid NaOH Negative Superfat')->exists())->toBeFalse();
});

it('replaces the working draft with the selected saved version using the same workbench payload shape', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Original Draft',
        'water_value' => 31,
        'superfat' => 4,
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published Baseline',
        'exposure_mode' => 'leave_on',
        'water_mode' => 'lye_ratio',
        'water_value' => 2.1,
        'superfat' => 7,
        'manufacturing_instructions' => '<p>Published baseline procedure.</p>',
    ]), $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedPayload = $service->versionPayload($recipe, $publishedVersion->id);

    $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published Baseline',
        'exposure_mode' => 'leave_on',
        'water_mode' => 'lye_ratio',
        'water_value' => 2.1,
        'superfat' => 7,
        'manufacturing_instructions' => '<p>Current revised procedure.</p>',
    ]), $recipe);

    $service->restoreCurrentVersion($user, $recipe, $publishedVersion->id);

    $actualPayload = $service->currentVersionPayload($recipe->fresh());

    expect($actualPayload)->not->toBeNull()
        ->and($expectedPayload['manufacturingInstructions'])->toBe('<p>Published baseline procedure.</p>')
        ->and(recipeWorkbenchComparableDraftPayload($actualPayload))
        ->toEqual(recipeWorkbenchComparableDraftPayload($expectedPayload))
        ->and($actualPayload['manufacturingInstructions'])->toBe('<p>Published baseline procedure.</p>')
        ->and($recipe->fresh()->manufacturing_instructions)->toBe('<p>Published baseline procedure.</p>')
        ->and($actualPayload['recipe']['is_current'])->toBeTrue()
        ->and($actualPayload['recipe']['version_number'])->toBeGreaterThan($publishedVersion->version_number)
        ->and($actualPayload['catalogReview']['needs_review'])->toBeFalse();
});

it('detects instructions-only differences before replacing the current version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $originalPayload = recipeWorkbenchLifecyclePayload($oil, [
        'manufacturing_instructions' => '<p>Original comparison procedure.</p>',
    ]);
    $draftVersion = $service->save($user, $soapFamily, $originalPayload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->publish($user, $soapFamily, $originalPayload, $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();

    $service->save($user, $soapFamily, [
        ...$originalPayload,
        'manufacturing_instructions' => '<p>Revised comparison procedure.</p>',
    ], $recipe);

    expect($service->currentVersionWouldBeReplacedByVersion($recipe, $publishedVersion->id))->toBeTrue();
});

it('publishes after restoring a recovery snapshot without reusing the recovery version number', function () {
    $user = User::factory()->create();
    grantLifecycleSavedFormulaHistory($user, 3);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
        'manufacturing_instructions' => '<p>Formula A procedure.</p>',
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
        'manufacturing_instructions' => '<p>Formula A procedure.</p>',
    ]), $recipe);
    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula B',
        'manufacturing_instructions' => '<p>Formula B procedure.</p>',
    ]), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $restoredVersion = $service->restorePublishedFormula($user, $recipe, $olderSavedVersion->id);
    $newDraft = $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published After Restore',
    ]), $recipe);

    expect($newDraft->is_current)->toBeTrue()
        ->and($restoredVersion->manufacturing_instructions)->toBe('<p>Formula A procedure.</p>')
        ->and($newDraft->version_number)->toBeGreaterThan($restoredVersion->version_number);
});

it('restores costing before pruning the oldest saved snapshot at plan capacity', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    grantLifecycleSavedFormulaHistory($user, 3);
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
    ]));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $mediaPath = MediaStorage::recipeDirectory($recipe, 'rich-content').'/restored-at-capacity.webp';
    $formulaAInstructions = '<p><img data-id="'.$mediaPath.'"></p>';
    Storage::disk('local')->put($mediaPath, 'restored-at-capacity-image');

    foreach (['Formula A', 'Formula B', 'Formula C', 'Formula D'] as $name) {
        $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
            'name' => $name,
            'manufacturing_instructions' => $name === 'Formula A'
                ? $formulaAInstructions
                : '<p>'.$name.' procedure.</p>',
        ]), $recipe);
    }

    $oldestSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->oldest('version_number')
        ->firstOrFail();
    attachRecipeLifecycleCosting($user, $oldestSavedVersion, $oil);

    expect(RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->count())->toBe(4);

    $restoredVersion = $service->restorePublishedFormula($user, $recipe, $oldestSavedVersion->id);
    $restoredCosting = RecipeVersionCosting::query()
        ->with(['items', 'packagingItems'])
        ->where('recipe_version_id', $restoredVersion->id)
        ->where('user_id', $user->id)
        ->first();

    expect(RecipeVersion::withoutGlobalScopes()->find($oldestSavedVersion->id))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->count())->toBe(4)
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->count())->toBe(1)
        ->and($restoredVersion->name)->toBe('Formula A')
        ->and($restoredVersion->manufacturing_instructions)->toBe($formulaAInstructions)
        ->and(Storage::disk('local')->exists($mediaPath))->toBeTrue()
        ->and($restoredCosting)->toBeInstanceOf(RecipeVersionCosting::class)
        ->and($restoredCosting?->oil_weight_for_costing)->toBe('1250.000')
        ->and($restoredCosting?->oil_unit_for_costing)->toBe('g')
        ->and($restoredCosting?->units_produced)->toBe(24)
        ->and($restoredCosting?->currency)->toBe('EUR')
        ->and($restoredCosting?->items)->toHaveCount(1)
        ->and($restoredCosting?->items->first()?->ingredient_id)->toBe($oil->id)
        ->and($restoredCosting?->items->first()?->phase_key)->toBe('saponified_oils')
        ->and($restoredCosting?->items->first()?->position)->toBe(1)
        ->and($restoredCosting?->items->first()?->price_per_kg)->toBe('8.5000')
        ->and($restoredCosting?->packagingItems)->toHaveCount(1)
        ->and($restoredCosting?->packagingItems->first()?->name)->toBe('Bottle')
        ->and($restoredCosting?->packagingItems->first()?->unit_cost)->toBe('1.2000')
        ->and($restoredCosting?->packagingItems->first()?->quantity)->toBe('10.000');
});

it('rolls back the restored formula and costing when retention pruning fails', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    grantLifecycleSavedFormulaHistory($user, 3);
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
    ]));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
    ]), $recipe);
    $sourceVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $sourceCosting = attachRecipeLifecycleCosting($user, $sourceVersion, $oil);
    $versionIdsBeforeRestore = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $this->mock(RecipeVersionDeletionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('pruneHiddenRecoverySnapshots')
            ->once()
            ->andThrow(new RuntimeException('Forced pruning failure.'));
    });

    expect(fn (): RecipeVersion => app(RecipeWorkbenchService::class)
        ->restorePublishedFormula($user, $recipe, $sourceVersion->id))
        ->toThrow(RuntimeException::class, 'Forced pruning failure.');

    expect(RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('id')
        ->pluck('id')
        ->all())->toBe($versionIdsBeforeRestore)
        ->and(RecipeVersionCosting::query()->where('recipe_version_id', $sourceVersion->id)->value('id'))->toBe($sourceCosting->id)
        ->and(RecipeVersionCosting::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RecipeVersionCostingItem::query()->count())->toBe(1)
        ->and(RecipeVersionCostingPackagingItem::query()->count())->toBe(1);
});

it('keeps only the latest saved snapshot and current version on the free plan', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']), $recipe);
    $oldestSavedVersionId = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->value('id');

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula B']), $recipe);

    expect(RecipeVersion::withoutGlobalScopes()->find($oldestSavedVersionId))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->pluck('name')->all())->toBe(['Formula B'])
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->count())->toBe(1);
});

it('keeps the latest save plus three earlier saves for a plan limit of three', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    grantLifecycleSavedFormulaHistory($user, 3);
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    foreach (['Formula A', 'Formula B', 'Formula C', 'Formula D'] as $name) {
        $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => $name]), $recipe);
    }

    $oldestSavedVersionId = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->oldest('version_number')
        ->value('id');

    expect(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->count())->toBe(4)
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->count())->toBe(1);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula E']), $recipe);

    expect(RecipeVersion::withoutGlobalScopes()->find($oldestSavedVersionId))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->orderBy('version_number')->pluck('name')->all())
        ->toBe(['Formula B', 'Formula C', 'Formula D', 'Formula E'])
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->count())->toBe(1);
});

it('retains published versions referenced by incomplete production snapshots during history pruning', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']), $recipe);
    $referencedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($referencedVersion, 'recipeVersion')
        ->create();

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula B']), $recipe);

    expect(RecipeVersion::withoutGlobalScopes()->find($referencedVersion->id))->not->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->pluck('name')->all())
        ->toBe(['Formula A', 'Formula B']);
});

it('prunes published versions once every referenced production snapshot is complete', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']), $recipe);
    $referencedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    $production = ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($referencedVersion, 'recipeVersion')
        ->create([
            'recipe_name_snapshot' => $recipe->name,
            'formula_snapshot_completed_at' => now(),
        ]);
    ProductionFormulaLine::factory()->for($production, 'productionRun')->create();

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula B']), $recipe);

    expect(RecipeVersion::withoutGlobalScopes()->find($referencedVersion->id))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->pluck('name')->all())
        ->toBe(['Formula B']);
});

it('blocks manual version deletion while an incomplete production snapshot still references it', function (): void {
    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']), $recipe);
    $referencedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($referencedVersion, 'recipeVersion')
        ->create();

    expect(fn () => app(RecipeVersionDeletionService::class)->delete($recipe, $referencedVersion))
        ->toThrow(ValidationException::class);

    expect(RecipeVersion::withoutGlobalScopes()->find($referencedVersion->id))->not->toBeNull();
});

it('deletes media orphaned when the free plan prunes an older saved snapshot', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    [$user, $soapFamily, $oil] = recipeHistoryLifecycleContext();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, ['name' => 'Formula A']));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $path = MediaStorage::recipeDirectory($recipe, 'rich-content').'/pruned-plan-history.webp';
    $instructions = '<p><img data-id="'.$path.'"></p>';
    Storage::disk('local')->put($path, 'pruned-plan-history-image');

    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
        'manufacturing_instructions' => $instructions,
    ]), $recipe);
    $service->publish($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula B',
        'manufacturing_instructions' => '<p>Formula B procedure.</p>',
    ]), $recipe);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

/** @return array{0: User, 1: ProductFamily, 2: Ingredient} */
function recipeHistoryLifecycleContext(): array
{
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap-'.fake()->unique()->slug(),
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    return [$user, $soapFamily, $oil];
}

function attachRecipeLifecycleCosting(
    User $user,
    RecipeVersion $version,
    Ingredient $ingredient,
): RecipeVersionCosting {
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1250,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 24,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'name' => 'Bottle',
        'unit_cost' => 1.2,
        'quantity' => 10,
    ]);

    return $costing;
}

function grantLifecycleSavedFormulaHistory(User $user, int $limit): void
{
    $plan = Plan::factory()
        ->hasLimit('saved_formula_history', $limit)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
}

function recipeWorkbenchLifecycleOil(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function recipeWorkbenchLifecyclePayload(Ingredient $oil, array $overrides = []): array
{
    $oilWeight = (float) ($overrides['oil_weight'] ?? 1000);
    $phaseItemsOverrides = is_array($overrides['phase_items'] ?? null) ? $overrides['phase_items'] : [];
    unset($overrides['phase_items']);

    $payload = array_merge([
        'name' => 'Recipe',
        'product_type_id' => testProductTypeIdForFamily('soap'),
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $oil->id,
                    'percentage' => 100,
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        if (array_key_exists($phaseKey, $phaseItemsOverrides)) {
            $payload['phase_items'][$phaseKey] = $phaseItemsOverrides[$phaseKey];
        }
    }

    return $payload;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function recipeWorkbenchComparableDraftPayload(array $payload): array
{
    $comparable = Arr::except($payload, ['recipe', 'catalogReview']);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        $comparable['phaseItems'][$phaseKey] = collect($comparable['phaseItems'][$phaseKey] ?? [])
            ->map(fn (array $row): array => Arr::except($row, ['id']))
            ->values()
            ->all();
    }

    return $comparable;
}
