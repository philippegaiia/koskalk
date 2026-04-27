<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('syncs the parent recipe name when a saved draft is renamed', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Recipe A'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Recipe B'),
        $recipe,
    );

    $recipe = $recipe->fresh();

    expect($recipe->name)->toBe('Recipe B')
        ->and(RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $draftVersion->recipe_id)
            ->where('is_draft', true)
            ->count())->toBe(1);
});

it('returns a structured error instead of throwing when oil weight is invalid', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->saveDraft(
        workbenchSoapDraftPayload($ingredient, oilWeight: 0),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('oil_weight')
        ->and($result['errors']['oil_weight'][0])->toContain('oil weight');
});

it('can still save a draft from a mounted component after the auth session is gone', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->saveDraft(
        workbenchSoapDraftPayload($ingredient, name: 'Fallback Draft'),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['recipe']['id'])->not->toBeNull();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['snapshot']['draft']['recipe']['id']);

    expect($recipe->owner_id)->toBe($user->id)
        ->and($recipe->name)->toBe('Fallback Draft')
        ->and($soapFamily->id)->toBe($recipe->product_family_id);
});

it('keeps instructions and media entered before the first draft is saved', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $component->data['description'] = '<p>Presentation ready before the first save.</p>';
    $component->data['manufacturing_instructions'] = '<p>Step 1: Prepare the mould.</p>';
    $component->data['featured_image_path'] = ['recipes/featured-images/first-draft.webp'];

    $result = $component->saveDraft(
        workbenchSoapDraftPayload($ingredient, name: 'Draft With Content'),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeTrue();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['snapshot']['draft']['recipe']['id']);

    expect($recipe->description)->toContain('Presentation ready before the first save')
        ->and($recipe->manufacturing_instructions)->toContain('Prepare the mould')
        ->and($recipe->featured_image_path)->toBe('recipes/featured-images/first-draft.webp');
});

it('returns backend soap calculation preview data for the workbench', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
    ]);
    $palmitic = FattyAcid::factory()->create([
        'key' => 'palmitic',
        'name' => 'Palmitic',
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 71,
    ]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $palmitic->id,
        'percentage' => 13,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        workbenchSoapDraftPayload($ingredient, oilWeight: 1000),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->not->toBeNull()
        ->and($result['calculation']['properties']['fatty_acid_profile']['oleic'])->toBe(71.0)
        ->and($result['calculation']['properties']['qualities'])->toHaveKey('unmolding_firmness');
});

it('does not re-render the workbench when refreshing the calculation preview', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    mock(RecipeWorkbenchViewDataBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([
                'productFamily' => [
                    'id' => 1,
                    'name' => 'Soap',
                    'slug' => 'soap',
                    'calculation_basis' => null,
                ],
                'recipe' => null,
                'savedSnapshot' => null,
                'phases' => [],
                'ingredients' => [],
                'ifraProductCategories' => [],
                'defaultIfraProductCategoryId' => null,
                'costing' => [],
            ]);
    });

    Livewire::test(RecipeWorkbench::class)
        ->call('previewCalculation', workbenchSoapDraftPayload($ingredient, oilWeight: 1000));
});

it('stores formula context on recipe versions and returns it in the draft payload', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, exposureMode: 'leave_on'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $draft = $service->draftPayload($recipe);
    $freshDraftVersion = $draftVersion->fresh();

    expect($freshDraftVersion)->not->toBeNull()
        ->and($freshDraftVersion?->manufacturing_mode)->toBe('saponify_in_formula')
        ->and($freshDraftVersion?->exposure_mode)->toBe('leave_on')
        ->and($freshDraftVersion?->regulatory_regime)->toBe('eu')
        ->and($freshDraftVersion?->catalog_reviewed_at)->not->toBeNull()
        ->and($draft['manufacturingMode'])->toBe('saponify_in_formula')
        ->and($draft['exposureMode'])->toBe('leave_on')
        ->and($draft['regulatoryRegime'])->toBe('eu')
        ->and($draft['catalogReview']['needs_review'])->toBeFalse();
});

it('preserves ingredient order across draft and saved versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeCarrierOilIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);
    $spirulina = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Spirulina',
        'inci_name' => 'SPIRULINA PLATENSIS POWDER',
        'is_active' => true,
    ]);
    $oatMilk = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Oat Milk Powder',
        'inci_name' => 'AVENA SATIVA KERNEL FLOUR',
        'is_active' => true,
    ]);

    $payload = workbenchSoapDraftPayload($oliveOil, name: 'Ordered Formula');
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 70,
            'weight' => 700,
            'note' => null,
        ],
        [
            'ingredient_id' => $oliveOil->id,
            'percentage' => 30,
            'weight' => 300,
            'note' => null,
        ],
    ];
    $payload['phase_items']['additives'] = [
        [
            'ingredient_id' => $oatMilk->id,
            'percentage' => 2,
            'weight' => 20,
            'note' => null,
        ],
        [
            'ingredient_id' => $spirulina->id,
            'percentage' => 1,
            'weight' => 10,
            'note' => null,
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, $payload, $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();
    $publishedVersion->load([
        'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
        'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
    ]);

    $draftPayload = $service->draftPayload($recipe);
    $publishedPayload = $service->versionPayload($recipe, $publishedVersion->id);

    expect(collect($draftPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and(collect($draftPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id])
        ->and(collect($publishedPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and(collect($publishedPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id])
        ->and($publishedVersion->phases->firstWhere('slug', 'saponified_oils')?->items->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and($publishedVersion->phases->firstWhere('slug', 'additives')?->items->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id]);
});

it('flags a saved formula for review when linked ingredient data changes', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    expect($service->draftPayload($recipe)['catalogReview']['needs_review'])->toBeFalse();

    $this->travel(1)->seconds();

    $ingredient->update([
        'display_name' => 'Updated Oil Name',
    ]);

    $updatedDraft = $service->draftPayload($recipe);

    expect($updatedDraft['catalogReview']['needs_review'])->toBeTrue()
        ->and($updatedDraft['catalogReview']['message'])->toContain('Recheck INCI and compliance');
});

it('loads a saved version for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Baseline Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $savedDraft = $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $savedDraft->recipe_id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['formulaName'])->toBe('Published Formula')
        ->and($result['snapshot']['calculation'])->not->toBeNull();
});

it('saves recipe content through the standalone filament form', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>A calming creamy bar for daily cleansing.</p>')
        ->set('data.manufacturing_instructions', '<p>Blend the base gently, then pour into the mould.</p>')
        ->set('data.featured_image_path', ['recipes/featured-images/soap.jpg'])
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect($recipe->fresh())
        ->description->toContain('calming creamy bar')
        ->manufacturing_instructions->toContain('Blend the base gently')
        ->featured_image_path->toBe('recipes/featured-images/soap.jpg');
});

it('returns the saved packaging item payload when saving a packaging catalog item', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->savePackagingCatalogItem(
        [
            'name' => 'Amber Jar',
            'unit_cost' => 1.2345,
            'currency' => 'EUR',
            'notes' => 'For 100 g bars',
        ],
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['packaging_catalog'])->toHaveCount(1)
        ->and($result['packaging_item'])->toMatchArray([
            'name' => 'Amber Jar',
            'unit_cost' => 1.2345,
            'currency' => 'EUR',
            'notes' => 'For 100 g bars',
        ])
        ->and($result['packaging_item']['id'])->toBeInt();

    expect(UserPackagingItem::query()
        ->where('user_id', $user->id)
        ->where('name', 'Amber Jar')
        ->value('unit_cost'))->toBe('1.2345');
});

it('serializes packaging plan row positions with the draft payload', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/payload.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export function /g, 'function ');

const normalizedIfraProductCategoryId = (value) => value;
const rowWeight = () => 0;
const nonNegativeNumber = (value) => Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : 0;

eval(`${source}\nglobalThis.serializeDraft = serializeDraft;`);

const payload = globalThis.serializeDraft({
  formulaName: 'Positioned packaging',
  oilUnit: 'g',
  oilWeight: 1000,
  phaseOrder: [],
  phaseItems: {},
  packagingPlanRows: [
    { id: 'box', user_packaging_item_id: 11, name: 'Box', components_per_unit: 1, notes: null },
    { id: 'label', user_packaging_item_id: 12, name: 'Label', components_per_unit: 2, notes: 'Front and back' },
  ],
});

console.log(JSON.stringify(payload.packaging_items));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $rows = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['position'])->toBe(1)
        ->and($rows[1]['position'])->toBe(2);
});

it('hydrates saved draft packaging rows into the workbench state', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/snapshot.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export function /g, 'function ');

const number = (value) => Number(value ?? 0);

eval(`${source}\nglobalThis.draftStateFromDraft = draftStateFromDraft;`);

const state = globalThis.draftStateFromDraft({
  phases: [{ key: 'phase_a', name: 'Phase A' }],
  phaseItems: { phase_a: [] },
  packagingItems: [
    {
      id: 'saved-packaging-14',
      user_packaging_item_id: 14,
      name: 'Amber Jar',
      components_per_unit: 1,
      notes: 'Primary pack',
    },
  ],
}, {
  recipeId: 10,
  draftVersionId: 20,
  currentVersionNumber: null,
  currentVersionIsDraft: true,
  productTypeId: null,
  formulaName: 'Draft',
  oilUnit: 'g',
  oilWeight: 100,
  manufacturingMode: 'blend_only',
  exposureMode: 'leave_on',
  regulatoryRegime: 'eu',
  editMode: 'percentage',
  lyeType: 'naoh',
  kohPurity: 90,
  dualKohPercentage: 40,
  waterMode: 'percent_of_oils',
  waterValue: 38,
  superfat: 5,
  phaseOrder: [{ key: 'phase_a', name: 'Phase A' }],
  packagingPlanRows: [],
  catalogReview: null,
});

console.log(JSON.stringify({
  packagingPlanRows: state.packagingPlanRows ?? [],
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingPlanRows'])->toHaveCount(1)
        ->and($payload['packagingPlanRows'][0])->toMatchArray([
            'id' => 'saved-packaging-14',
            'user_packaging_item_id' => 14,
            'name' => 'Amber Jar',
            'components_per_unit' => 1,
            'notes' => 'Primary pack',
        ]);
});

it('does not expose legacy packaging costing structure helpers', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingUnitsProduced: 12,
  costingSaveTimer: null,
  packagingCostRows: [],
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return 'row-1';
  },
};

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

globalThis.createCostingSection = undefined;
eval(`${source}\nglobalThis.createCostingSection = createCostingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

console.log(JSON.stringify({
  hasAddPackagingCostRow: typeof state.addPackagingCostRow === 'function',
  hasRemovePackagingCostRow: typeof state.removePackagingCostRow === 'function',
  hasSaveAndAddToCosting: typeof state.savePackagingCatalogItemAndAddToCosting === 'function',
  hasUnusedPackagingCatalogItems: Object.prototype.hasOwnProperty.call(Object.getOwnPropertyDescriptors(createCostingSection({})), 'unusedPackagingCatalogItems'),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['hasAddPackagingCostRow'])->toBeFalse()
        ->and($payload['hasRemovePackagingCostRow'])->toBeFalse()
        ->and($payload['hasSaveAndAddToCosting'])->toBeFalse()
        ->and($payload['hasUnusedPackagingCatalogItems'])->toBeFalse();
});

it('does not load costing when the packaging tab is opened', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const refreshWorkbenchLabelingPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({
  addIngredient() {},
});
const createPackagingSection = () => ({});
const createCostingSection = () => ({
  initializeCostingState() {},
  ensureCostingLoaded() {
    this.ensureCostingLoadedCalls = (this.ensureCostingLoadedCalls ?? 0) + 1;
  },
  resetPackagingCatalogForm() {},
  reconcileCostingPrices() {},
});
const createPresentationSection = () => ({
  syncIngredientListVariantSelection() {},
});
const createVersionSection = () => ({});
`;

globalThis.window = {
  location: { hash: '' },
  addEventListener() {},
  removeEventListener() {},
};
globalThis.document = {
  addEventListener() {},
  removeEventListener() {},
};

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const watchers = {};
const workbench = globalThis.createRecipeWorkbench({
  phases: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
  ingredients: [],
  recipe: { id: 5, draft_version_id: 8 },
});

workbench.$watch = (key, callback) => {
  watchers[key] = callback;
};

workbench.init();
workbench.activeWorkbenchTab = 'packaging';
watchers.activeWorkbenchTab?.('packaging');

console.log(JSON.stringify({
  ensureCostingLoadedCalls: workbench.ensureCostingLoadedCalls ?? 0,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['ensureCostingLoadedCalls'])->toBe(0);
});

it('seeds the packaging catalog from the initial workbench payload', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const refreshWorkbenchLabelingPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({
  initializeCostingState() {},
  ensureCostingLoaded() {},
  resetPackagingCatalogForm() {},
  reconcileCostingPrices() {},
});
const createPresentationSection = () => ({
  syncIngredientListVariantSelection() {},
});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
  packagingCatalog: [
    {
      id: 44,
      name: 'Amber Jar',
      unit_cost: 0.82,
      currency: 'EUR',
      notes: 'Reusable catalog item',
    },
  ],
  defaultCurrency: 'EUR',
});

console.log(JSON.stringify({
  packagingCatalogCount: workbench.packagingCatalog.length,
  firstPackagingItem: workbench.packagingCatalog[0] ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['firstPackagingItem'])->toMatchArray([
            'id' => 44,
            'name' => 'Amber Jar',
            'unit_cost' => 0.82,
            'currency' => 'EUR',
            'notes' => 'Reusable catalog item',
        ]);
});

it('filters the packaging catalog by a case-insensitive search term', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  packagingCatalogSearch: 'jar',
  packagingCatalog: [
    { id: 1, name: 'Amber Jar', notes: 'Glass' },
    { id: 2, name: 'Wrap Label', notes: 'Paper band' },
    { id: 3, name: 'Lid', notes: 'Jar closure' },
  ],
};

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/packaging-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createPackagingSection', 'function createPackagingSection');

globalThis.createPackagingSection = undefined;
eval(`${source}\nglobalThis.createPackagingSection = createPackagingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPackagingSection()));

console.log(JSON.stringify({
  filteredIds: state.filteredPackagingCatalog.map((item) => item.id),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['filteredIds'])->toBe([1, 3]);
});

it('includes the user packaging catalog on the rendered workbench component', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Amber Jar',
        'unit_cost' => 0.82,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect($workbench['packagingCatalog'])->toHaveCount(1)
        ->and($workbench['packagingCatalog'][0])->toMatchArray([
            'name' => 'Amber Jar',
            'unit_cost' => 0.82,
            'currency' => 'EUR',
            'notes' => 'Reusable catalog item',
        ]);
});

it('can save a packaging catalog item and add it to the packaging plan', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingCurrency: 'EUR',
  packagingCatalog: [],
  packagingPlanRows: [],
  packagingCostRows: [],
  packagingCatalogStatus: null,
  packagingCatalogMessage: '',
  packagingCatalogForm: {
    id: null,
    name: 'Amber Jar',
    unit_cost: 1.2345,
    currency: 'EUR',
    notes: 'For boxed bars',
  },
  hasSavedRecipe: true,
  costingSaveTimer: null,
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return `row-${this.packagingCostRows.length + 1}`;
  },
  resetPackagingCatalogForm() {
    this.packagingCatalogForm = {
      id: null,
      name: '',
      unit_cost: '',
      currency: this.costingCurrency ?? 'EUR',
      notes: '',
    };
  },
  $wire: {
    async savePackagingCatalogItem(payload) {
      return {
        ok: true,
        message: 'Packaging item saved.',
        packaging_catalog: [
          {
            id: 41,
            name: payload.name,
            unit_cost: payload.unit_cost,
            currency: payload.currency,
            notes: payload.notes,
          },
        ],
        packaging_item: {
          id: 41,
          name: payload.name,
          unit_cost: payload.unit_cost,
          currency: payload.currency,
          notes: payload.notes,
        },
      };
    },
  },
};

const bridgeSource = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/bridge.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export async function /g, 'async function ');

const costingSource = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

const packagingSource = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/packaging-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createPackagingSection', 'function createPackagingSection');

globalThis.persistPackagingCatalogItem = undefined;
globalThis.persistCosting = async () => {};
globalThis.createCostingSection = undefined;
globalThis.createPackagingSection = undefined;
eval(`${bridgeSource}\nglobalThis.persistPackagingCatalogItem = persistPackagingCatalogItem;`);
eval(`const persistCosting = globalThis.persistCosting;\n${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);
eval(`${packagingSource}\nglobalThis.createPackagingSection = createPackagingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPackagingSection()));
Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

await state.savePackagingCatalogItemAndAdd();

console.log(JSON.stringify({
  packagingCatalogCount: state.packagingCatalog.length,
  packagingCatalogStatus: state.packagingCatalogStatus,
  packagingCatalogMessage: state.packagingCatalogMessage,
  packagingCatalogModalOpen: state.packagingCatalogModalOpen,
  row: state.packagingPlanRows[0] ?? null,
  costingRows: state.packagingCostRows.length,
  form: state.packagingCatalogForm,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['packagingCatalogStatus'])->toBe('success')
        ->and($payload['packagingCatalogMessage'])->toBe('Packaging item saved.')
        ->and($payload['packagingCatalogModalOpen'])->toBeFalse()
        ->and($payload['row'])->toMatchArray([
            'user_packaging_item_id' => 41,
            'name' => 'Amber Jar',
            'components_per_unit' => 1,
            'notes' => '',
        ])
        ->and($payload['costingRows'])->toBe(0)
        ->and($payload['form'])->toMatchArray([
            'id' => null,
            'name' => '',
            'unit_cost' => '',
            'currency' => 'EUR',
            'notes' => '',
        ]);
});

it('adds a saved catalog item to the packaging plan with one component per unit', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  packagingPlanRows: [],
  makeLocalPackagingPlanRowId() {
    return `row-${this.packagingPlanRows.length + 1}`;
  },
};

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/packaging-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createPackagingSection', 'function createPackagingSection');

globalThis.createPackagingSection = undefined;
eval(`${source}\nglobalThis.createPackagingSection = createPackagingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPackagingSection()));

state.addPackagingPlanRow({
  id: 91,
  name: 'Soap box',
  unit_cost: 0.42,
});

console.log(JSON.stringify({
  row: state.packagingPlanRows[0] ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['row'])->toMatchArray([
        'user_packaging_item_id' => 91,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'notes' => '',
    ]);
});

it('allows carrier oils to move between soap oils and additives', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [
    {
      id: 1,
      name: 'Olive Oil',
      inci_name: 'OLEA EUROPAEA FRUIT OIL',
      category: 'carrier_oil',
      available_phases: ['saponified_oils', 'additives'],
      can_add_to_saponified_oils: true,
      can_add_to_additives: true,
    },
  ],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, name: 'Olive Oil', category: 'carrier_oil' },
  ],
  additives: [],
  fragrance: [],
};

const event = {
  preventDefault() {},
  dataTransfer: {
    effectAllowed: '',
    dropEffect: '',
    setData() {},
  },
};

workbench.beginRowDrag('saponified_oils', 'oil-1', event);

const canDropIntoAdditives = workbench.canDropRowInPhase('additives');

workbench.dropDraggedRow('additives', event);

console.log(JSON.stringify({
  canDropIntoAdditives,
  oilCount: workbench.phaseItems.saponified_oils.length,
  additiveCount: workbench.phaseItems.additives.length,
  additiveRowId: workbench.phaseItems.additives[0]?.id ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['canDropIntoAdditives'])->toBeTrue()
        ->and($payload['oilCount'])->toBe(0)
        ->and($payload['additiveCount'])->toBe(1)
        ->and($payload['additiveRowId'])->toBe('oil-1');
});

it('still allows reordering rows within the same phase', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [
    { id: 1, available_phases: ['saponified_oils'], can_add_to_saponified_oils: true },
    { id: 2, available_phases: ['saponified_oils'], can_add_to_saponified_oils: true },
  ],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, name: 'Olive Oil' },
    { id: 'oil-2', ingredient_id: 2, name: 'Coconut Oil' },
  ],
  additives: [],
  fragrance: [],
};

const event = {
  preventDefault() {},
  dataTransfer: {
    effectAllowed: '',
    dropEffect: '',
    setData() {},
  },
};

workbench.beginRowDrag('saponified_oils', 'oil-1', event);
workbench.dropDraggedRow('saponified_oils', event);

console.log(JSON.stringify({
  oilIds: workbench.phaseItems.saponified_oils.map((row) => row.id),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['oilIds'])->toBe(['oil-2', 'oil-1']);
});

it('only schedules the soap calculation preview when reaction-core rows change', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, percentage: 100 },
  ],
  additives: [],
  fragrance: [],
};

let calculationSchedules = 0;
let labelingSchedules = 0;

workbench.scheduleCalculationPreview = () => {
  calculationSchedules += 1;
};

workbench.scheduleLabelingPreview = () => {
  labelingSchedules += 1;
};

workbench.lastCalculationPhaseSignature = workbench.currentCalculationPhaseSignature();
workbench.phaseItems.fragrance.push({ id: 'frag-1', ingredient_id: 9, percentage: 2 });
workbench.schedulePhaseItemPreviews();

console.log(JSON.stringify({
  calculationSchedules,
  labelingSchedules,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['calculationSchedules'])->toBe(0)
        ->and($payload['labelingSchedules'])->toBe(1);
});

it('still schedules the soap calculation preview when reaction-core rows change', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const CATEGORY_OPTIONS = [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, percentage: 100 },
  ],
  additives: [],
  fragrance: [],
};

let calculationSchedules = 0;
let labelingSchedules = 0;

workbench.scheduleCalculationPreview = () => {
  calculationSchedules += 1;
};

workbench.scheduleLabelingPreview = () => {
  labelingSchedules += 1;
};

workbench.lastCalculationPhaseSignature = workbench.currentCalculationPhaseSignature();
workbench.phaseItems.saponified_oils[0].percentage = 85;
workbench.schedulePhaseItemPreviews();

console.log(JSON.stringify({
  calculationSchedules,
  labelingSchedules,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['calculationSchedules'])->toBe(1)
        ->and($payload['labelingSchedules'])->toBe(1);
});

it('keeps the save-only packaging success message visible after closing the modal', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingCurrency: 'EUR',
  packagingCatalog: [],
  packagingCostRows: [],
  packagingCatalogStatus: null,
  packagingCatalogMessage: '',
  packagingCatalogForm: {
    id: null,
    name: 'Amber Jar',
    unit_cost: 1.2345,
    currency: 'EUR',
    notes: 'For boxed bars',
  },
  hasSavedRecipe: true,
  costingSaveTimer: null,
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return `row-${this.packagingCostRows.length + 1}`;
  },
  resetPackagingCatalogForm() {
    this.packagingCatalogForm = {
      id: null,
      name: '',
      unit_cost: '',
      currency: this.costingCurrency ?? 'EUR',
      notes: '',
    };
  },
  $wire: {
    async savePackagingCatalogItem(payload) {
      return {
        ok: true,
        message: 'Packaging item saved.',
        packaging_catalog: [
          {
            id: 41,
            name: payload.name,
            unit_cost: payload.unit_cost,
            currency: payload.currency,
            notes: payload.notes,
          },
        ],
        packaging_item: {
          id: 41,
          name: payload.name,
          unit_cost: payload.unit_cost,
          currency: payload.currency,
          notes: payload.notes,
        },
      };
    },
  },
};

const bridgeSource = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/bridge.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export async function /g, 'async function ');

const costingSource = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

globalThis.persistPackagingCatalogItem = undefined;
globalThis.persistCosting = async () => {};
globalThis.createCostingSection = undefined;
eval(`${bridgeSource}\nglobalThis.persistPackagingCatalogItem = persistPackagingCatalogItem;`);
eval(`const persistCosting = globalThis.persistCosting;\n${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

await state.savePackagingCatalogItemOnly();

console.log(JSON.stringify({
  packagingCatalogCount: state.packagingCatalog.length,
  packagingCatalogStatus: state.packagingCatalogStatus,
  packagingCatalogMessage: state.packagingCatalogMessage,
  packagingCatalogModalOpen: state.packagingCatalogModalOpen,
  form: state.packagingCatalogForm,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['packagingCatalogStatus'])->toBe('success')
        ->and($payload['packagingCatalogMessage'])->toBe('Packaging item saved.')
        ->and($payload['packagingCatalogModalOpen'])->toBeFalse()
        ->and($payload['form'])->toMatchArray([
            'id' => null,
            'name' => '',
            'unit_cost' => '',
            'currency' => 'EUR',
            'notes' => '',
        ]);
});

it('deletes the previous recipe featured image from storage when the image is cleared', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'featured_image_path' => 'recipes/featured-images/original.webp',
    ]);

    Storage::disk('public')->put('recipes/featured-images/original.webp', 'old-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Presentation only.</p>')
        ->set('data.manufacturing_instructions', '<p>Manufacturing only.</p>')
        ->set('data.featured_image_path', null)
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('public')->exists('recipes/featured-images/original.webp'))->toBeFalse()
        ->and($recipe->fresh()->featured_image_path)->toBeNull();
});

it('keeps a shared rich content attachment when it is moved between recipe editors in one save', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $sharedAttachment = 'recipes/rich-content/shared.webp';
    $sharedHtml = '<p><img data-id="'.$sharedAttachment.'" src="/storage/'.$sharedAttachment.'"></p>';

    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'description' => '<p>Presentation intro.</p>',
        'manufacturing_instructions' => $sharedHtml,
    ]);

    Storage::disk('public')->put($sharedAttachment, 'shared-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', $sharedHtml)
        ->set('data.manufacturing_instructions', '<p>Step 1: Warm the oils.</p>')
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('public')->exists($sharedAttachment))->toBeTrue()
        ->and($recipe->fresh()->description)->toContain($sharedAttachment)
        ->and($recipe->fresh()->manufacturing_instructions)->not->toContain($sharedAttachment);
});

it('keeps comparison snapshots aligned with the version payload and backend calculation', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Comparison Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Comparison Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot);
});

it('loads saved versions with the same snapshot contract used for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Opened Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->loadVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot)
        ->and($result['message'])->toContain('Saved version loaded');
});

it('returns no soap calculation preview for blend-only formulas', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $draft = workbenchSoapDraftPayload($ingredient, oilWeight: 1000);
    $draft['manufacturing_mode'] = 'blend_only';

    $result = $component->previewCalculation(
        $draft,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->toBeNull();
});

it('exposes workbench phase options for saponifiable oils, additive-only oils, and aromatics', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $trustedCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    $customCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Custom Fig Oil',
        'is_potentially_saponifiable' => false,
        'is_active' => true,
    ]);

    $fragranceIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Rose Accord',
        'is_active' => true,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];
    $ingredients = collect($workbench['ingredients'])->keyBy('id');

    expect($ingredients)->toHaveKeys([
        $trustedCarrierIngredient->id,
        $customCarrierIngredient->id,
        $fragranceIngredient->id,
    ])
        ->and($ingredients[$trustedCarrierIngredient->id]['available_phases'])->toBe(['saponified_oils', 'additives'])
        ->and($ingredients[$trustedCarrierIngredient->id]['default_phase'])->toBe('saponified_oils')
        ->and($ingredients[$customCarrierIngredient->id]['available_phases'])->toBe(['additives'])
        ->and($ingredients[$customCarrierIngredient->id]['default_phase'])->toBe('additives')
        ->and($ingredients[$fragranceIngredient->id]['available_phases'])->toBe(['fragrance'])
        ->and($ingredients[$fragranceIngredient->id]['needs_compliance'])->toBeTrue();
});

it('orders ifra categories naturally and exposes cat 9 as the default soap context', function () {
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $category10A = IfraProductCategory::factory()->create([
        'code' => '10A',
        'short_name' => 'Household hand contact',
        'is_active' => true,
    ]);
    $category9 = IfraProductCategory::factory()->create([
        'code' => '9',
        'short_name' => 'Soap / shower gel / rinse-off',
        'is_active' => true,
    ]);
    $category2 = IfraProductCategory::factory()->create([
        'code' => '2',
        'short_name' => 'Deodorants / axillae',
        'is_active' => true,
    ]);

    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category10A->id,
        'is_default' => false,
        'sort_order' => 3,
    ]);
    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category9->id,
        'is_default' => true,
        'sort_order' => 2,
    ]);
    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category2->id,
        'is_default' => false,
        'sort_order' => 1,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect(collect($workbench['ifraProductCategories'])->pluck('code')->all())
        ->toBe(['2', '9', '10A'])
        ->and($workbench['defaultIfraProductCategoryId'])->toBe($category9->id);
});

it('keeps formula table controls stepped and visually aligned', function () {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();

    expect($reactionCore)
        ->toContain('grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('type="number" inputmode="decimal" min="0" max="100" step="1"')
        ->toContain('row.percentage = format(clampPercentage($event.target.value), 2)')
        ->toContain('format(totalOilPercentage(), 2)')
        ->toContain("oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)] text-[var(--color-ink-strong)]'")
        ->toContain('document.activeElement !== $el')
        ->not->toContain(':value="format(rowWeight(row), 1)"')
        ->and($postReaction)
        ->toContain('grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('type="number" inputmode="decimal" min="0" max="100" step="0.1"')
        ->toContain('type="number" inputmode="decimal" step="0.001"')
        ->toContain('row.percentage = clampPercentage($event.target.value)')
        ->toContain('document.activeElement !== $el')
        ->not->toContain(':value="format(rowWeight(row), 3)"');
});

it('keeps packaging catalog controls below the intro in a horizontal row', function () {
    $packagingTab = view('livewire.dashboard.partials.recipe-workbench.packaging-tab')->render();

    expect($packagingTab)
        ->toContain('max-w-3xl text-sm text-[var(--color-ink-soft)]')
        ->toContain('flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center')
        ->toContain('sm:w-72')
        ->toContain('sm:min-w-72')
        ->not->toContain('lg:flex-row lg:items-start lg:justify-between');
});

it('keeps formula visual states distinct and softly selected', function () {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation')->render();

    expect($reactionCore)
        ->toContain('class="numeric rounded-full bg-white px-3 py-1 text-sm font-semibold" x-text="`${format(totalOilPercentage(), 2)}%`"')
        ->and($formulaAnalysis)
        ->toContain('rounded-lg border px-4 py-3 text-sm')
        ->and($ingredientBrowser)
        ->toContain('flex min-w-0 items-center justify-between gap-3 rounded-lg bg-[var(--color-field)] px-3 py-2 text-xs')
        ->toContain('min-w-0 flex-1 truncate text-[var(--color-ink-strong)]')
        ->and($navigation)
        ->toContain('bg-[var(--color-accent-soft)]');
});

it('keeps fatty acid chemistry compact with grouped profile first and collapsed details', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($ingredientBrowser)
        ->not->toContain('Live blend feedback.')
        ->toContain('fattyAcidChemistrySummaryRows()')
        ->toContain('grid grid-cols-3 gap-2')
        ->toContain('<details class="rounded-lg border border-[var(--color-line)] bg-[var(--color-field)]"', false)
        ->toContain('<summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 marker:hidden"', false)
        ->toContain('x-text="`${fattyAcidProfileRows.length} acids`"')
        ->toContain('grid-cols-[minmax(0,5.5rem)_minmax(3rem,1fr)_4.25rem]')
        ->and($presentationSection)
        ->toContain('Sat / Unsat')
        ->toContain('Iodine')
        ->toContain('INS')
        ->toContain('qualityTargetRangeLabel(\'iodine\')')
        ->toContain('qualityTargetRangeLabel(\'ins\')')
        ->toContain('fattyAcidSatUnsatRatio')
        ->not->toContain('Unsaturation')
        ->not->toContain('Soap balance');
});

it('presents soap qualities as compact tabbed metric cards', function () {
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));

    expect($formulaAnalysis)
        ->not->toContain('xl:col-span-2')
        ->toContain("x-data=\"{ soapQualityPanel: 'qualities' }\"", false)
        ->toContain("soapQualityPanel = 'qualities'")
        ->toContain("soapQualityPanel = 'advanced'")
        ->toContain('defaultQualityRows()')
        ->toContain('advancedQualityRows()')
        ->toContain('grid gap-3 sm:grid-cols-2 xl:grid-cols-4')
        ->toContain('rounded-lg border px-4 py-3 text-sm')
        ->toContain('qualityCardStyle(row.key, row.value)')
        ->toContain('qualityTargetLabel(row.key)')
        ->toContain('targetZoneStyle(row.key)')
        ->and($presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js')))
        ->toContain('if (numeric <= zone.end) return \'ideal\';')
        ->toContain('return numeric < 85 ? \'high\' : \'excess\';')
        ->toContain('iodine: { start: 41, end: 70 }')
        ->toContain('ins: { start: 136, end: 165 }')
        ->and($formulaAnalysis)
        ->not->toContain('sk-quality-pill shrink-0')
        ->not->toContain('Compact interpretation first, deeper chemistry second.')
        ->not->toContain('Deeper structure signals, including iodine and INS.')
        ->not->toContain('Advanced metrics')
        ->and($formulaTabSource)
        ->toContain('@include(\'livewire.dashboard.partials.recipe-workbench.formula-analysis\')')
        ->toContain('@include(\'livewire.dashboard.partials.recipe-workbench.post-reaction\')');
});

function makeCarrierOilIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function workbenchSoapDraftPayload(
    Ingredient $ingredient,
    string $name = 'Recipe',
    float $oilWeight = 1000,
    string $exposureMode = 'rinse_off',
): array {
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => $exposureMode,
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
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
