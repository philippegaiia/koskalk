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

it('defaults a new packaging row to one component per finished unit in the workbench flow', function () {
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

state.addPackagingCostRow();

console.log(JSON.stringify(state.packagingCostRows[0]));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $row = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($row['quantity'])->toBe(1);
});

it('can save a packaging catalog item and add it to costing at one component per finished unit', function () {
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

await state.savePackagingCatalogItemAndAddToCosting();

console.log(JSON.stringify({
  packagingCatalogCount: state.packagingCatalog.length,
  packagingCatalogStatus: state.packagingCatalogStatus,
  packagingCatalogMessage: state.packagingCatalogMessage,
  packagingCatalogModalOpen: state.packagingCatalogModalOpen,
  row: state.packagingCostRows[0] ?? null,
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
            'unit_cost' => 1.2345,
            'quantity' => 1,
        ])
        ->and($payload['form'])->toMatchArray([
            'id' => null,
            'name' => '',
            'unit_cost' => '',
            'currency' => 'EUR',
            'notes' => '',
        ]);
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
