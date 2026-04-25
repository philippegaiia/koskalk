<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\InciGenerationService;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeWorkbenchDraftPayloadMapper;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('requires a cosmetic product type before entering the shared cosmetic workbench', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
        'is_active' => true,
        'sort_order' => 10,
    ]);
    ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Hidden cosmetic type',
        'slug' => 'hidden-cosmetic-type',
        'is_active' => false,
        'sort_order' => 20,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => 'cosmetic']))
        ->assertSuccessful()
        ->assertSee('Choose a cosmetic product type')
        ->assertSee('Cream / lotion')
        ->assertDontSee('Hidden cosmetic type');

    $this->actingAs($user)
        ->get(route('recipes.create', [
            'family' => 'cosmetic',
            'type' => 'cream-lotion',
        ]))
        ->assertSuccessful()
        ->assertSee('Editable draft')
        ->assertSee('Formula')
        ->assertSee('Costing')
        ->assertSee('Output')
        ->assertSee('Instructions &amp; Media', false)
        ->assertSee('Cream / lotion')
        ->assertSee('Batch weight')
        ->assertSee('Formula ingredients')
        ->assertSee('updateCosmeticPercentagesFromWeights', false)
        ->assertSee('Phase A')
        ->assertDontSee('Cosmetic workbench')
        ->assertDontSee('Lye type')
        ->assertDontSee('Water mode')
        ->assertDontSee('Superfat')
        ->assertDontSee('Saponified oils + lye water');
});

it('keeps cosmetic formula percentages balanced when editing weights', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('/Users/philippe/Herd/koskalk/resources/js/recipe-workbench/calculation.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replaceAll('export function', 'function');

const stubs = `
const number = (value) => {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : 0;
};
const nonNegativeNumber = (value) => Math.max(0, number(value));
const roundTo = (value, decimals = 3) => {
  const factor = 10 ** decimals;
  return Math.round(number(value) * factor) / factor;
};
`;

eval(`${stubs}\n${source}\nglobalThis.updateFormulaPercentagesFromWeights = updateFormulaPercentagesFromWeights;`);

const rows = [
  { id: 'water', percentage: 50 },
  { id: 'glycerin', percentage: 50 },
];

const updated = globalThis.updateFormulaPercentagesFromWeights(rows, 100, 'water', 30);

console.log(JSON.stringify({
  totalWeight: updated.totalWeight,
  water: updated.percentagesByRowId.get('water'),
  glycerin: updated.percentagesByRowId.get('glycerin'),
  totalPercentage: Array.from(updated.percentagesByRowId.values()).reduce((total, value) => total + value, 0),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result['totalWeight'])->toBe(80)
        ->and($result['water'])->toBe(37.5)
        ->and($result['glycerin'])->toBe(62.5)
        ->and($result['totalPercentage'])->toBe(100);
});

it('does not preselect an IFRA category for new cosmetic formulas and exposes every active category', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $category1 = IfraProductCategory::factory()->create([
        'code' => '1',
        'is_active' => true,
    ]);
    $category9 = IfraProductCategory::factory()->create([
        'code' => '9',
        'is_active' => true,
    ]);
    $category12 = IfraProductCategory::factory()->create([
        'code' => '12',
        'is_active' => true,
    ]);
    IfraProductCategory::factory()->create([
        'code' => '11',
        'is_active' => false,
    ]);

    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
        'default_ifra_product_category_id' => $category9->id,
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount(null, 'cosmetic', 'cream-lotion');

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect($workbench['defaultIfraProductCategoryId'])->toBeNull()
        ->and(collect($workbench['ifraProductCategories'])->pluck('code')->all())->toBe(['1', '9', '12'])
        ->and(collect($workbench['ifraProductCategories'])->pluck('id')->all())->toContain($category1->id, $category9->id, $category12->id)
        ->and($workbench['productType']['id'])->toBe($productType->id);
});

it('uses wider cosmetic percentage and weight columns with half-gram weight steps', function () {
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($cosmeticFormula)
        ->toContain('grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('type="number" inputmode="decimal" step="0.5"');
});

it('allows incomplete cosmetic drafts but requires saved cosmetic formulas to total 100 percent', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $ingredient = cosmeticIngredient('Glycerin', 'GLYCERIN');
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($ingredient, percentage: 60, weight: 300),
            ],
        ]),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $phase = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $draftVersion->id)
        ->firstOrFail();
    $item = RecipeItem::withoutGlobalScopes()
        ->where('recipe_phase_id', $phase->id)
        ->firstOrFail();

    expect($recipe->product_type_id)->toBe($productType->id)
        ->and($recipe->product_family_id)->toBe($cosmeticFamily->id)
        ->and($draftVersion->is_draft)->toBeTrue()
        ->and($draftVersion->batch_size)->toEqual('500.000')
        ->and($draftVersion->calculation_context['formula_total_percentage'])->toEqual(60.0)
        ->and($phase->name)->toBe('Phase A')
        ->and((float) $item->percentage)->toBe(60.0);

    expect(fn () => $service->saveRecipe(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($ingredient, percentage: 60, weight: 300),
            ],
        ]),
        $recipe,
    ))->toThrow(ValidationException::class, 'Cosmetic formula must total 100% before it can be saved.');

    $savedDraftVersion = $service->saveRecipe(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($ingredient, percentage: 100, weight: 500),
            ],
        ]),
        $recipe,
    );

    expect($savedDraftVersion->is_draft)->toBeTrue()
        ->and(Recipe::withoutGlobalScopes()->findOrFail($recipe->id)->product_type_id)->toBe($productType->id);
});

it('aggregates duplicate cosmetic INCI ingredients across phases by total formula percentage', function () {
    $aqua = cosmeticIngredient('Water', 'AQUA');
    $glycerinA = cosmeticIngredient('Glycerin A', 'GLYCERIN');
    $glycerinB = cosmeticIngredient('Glycerin B', 'GLYCERIN');

    $labeling = app(InciGenerationService::class)->generate(cosmeticDraftPayload(null, [
        'phase_a' => [
            cosmeticPayloadRow($glycerinA, percentage: 10, weight: 50),
            cosmeticPayloadRow($aqua, percentage: 50, weight: 250),
        ],
        'phase_b' => [
            cosmeticPayloadRow($glycerinB, percentage: 20, weight: 100),
        ],
    ]));

    expect($labeling['ingredient_rows'])->toHaveCount(2)
        ->and($labeling['ingredient_rows'][0]['label'])->toBe('AQUA')
        ->and($labeling['ingredient_rows'][0]['percent_of_formula'])->toBe(50.0)
        ->and($labeling['ingredient_rows'][1]['label'])->toBe('GLYCERIN')
        ->and($labeling['ingredient_rows'][1]['percent_of_formula'])->toBe(30.0)
        ->and($labeling['final_label_text'])->toBe('AQUA, GLYCERIN');
});

it('lets the shared recipe workbench save incomplete cosmetic drafts', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');
    cosmeticIngredient('Glycerin', 'GLYCERIN');

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount(null, 'cosmetic', 'cream-lotion');
    $result = $component->saveDraft(
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($aqua, percentage: 60, weight: 60),
            ],
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    $recipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Daily Moisturizer')
        ->firstOrFail();

    expect($result['ok'])->toBeTrue()
        ->and($result['redirect'])->toBe(route('recipes.edit', $recipe->id))
        ->and($recipe->product_type_id)->toBe($productType->id)
        ->and(RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->exists())->toBeFalse()
        ->and(RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->exists())->toBeTrue();
});

it('starts cosmetic formulas at a 100 gram batch by default in shared state', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));

    expect($componentSource)
        ->toContain("formulaName: isCosmeticFormula ? 'New Cosmetic Formula' : 'New Soap Formula'")
        ->toContain('oilWeight: isCosmeticFormula ? 100 : 1000')
        ->toContain("manufacturingMode: isCosmeticFormula ? 'blend_only' : 'saponify_in_formula'")
        ->toContain("exposureMode: isCosmeticFormula ? 'leave_on' : 'rinse_off'");
});

it('exposes practical controls for choosing and reordering cosmetic phases', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser', [
        'isCosmeticWorkbench' => true,
    ])->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $formulaSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));

    expect($ingredientBrowser)
        ->toContain('phaseOrder.length <= 1')
        ->toContain('phaseOrder.length > 1')
        ->toContain('Add to')
        ->and($cosmeticFormula)
        ->toContain('Drop here')
        ->toContain("moveCosmeticPhase(phase.key, 'up')")
        ->toContain("moveCosmeticPhase(phase.key, 'down')")
        ->and($formulaSource)
        ->toContain('moveCosmeticPhase(phaseKey, direction)')
        ->toContain('cosmeticPhaseIsFirst')
        ->toContain('cosmeticPhaseIsLast');
});

it('keeps cosmetic phase editing calm and guarded', function () {
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $formulaSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));

    expect($cosmeticFormula)
        ->toContain('border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]')
        ->toContain('type="number" inputmode="decimal" min="0" max="100" step="0.1"')
        ->toContain('row.percentage = format(clampPercentage($event.target.value), 2)')
        ->toContain('document.activeElement !== $el')
        ->not->toContain(':value="format(rowWeight(row), 3)"')
        ->not->toContain("'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]'")
        ->toContain('Drop here')
        ->not->toContain('Drop here to move to the end of this phase')
        ->not->toContain('Phase total')
        ->toContain('% of formula')
        ->toContain('confirmRemoveCosmeticPhase(phase.key)')
        ->toContain('items-center')
        ->and($formulaSource)
        ->toContain('confirmRemoveCosmeticPhase(phaseKey)')
        ->toContain('Remove this phase and its ingredients?')
        ->and($componentSource)
        ->toContain('beforeunload')
        ->toContain('livewire:navigate')
        ->toContain('hasUnsavedWorkbenchChanges')
        ->toContain('refreshDirtyBaseline');
});

it('keeps the cosmetic workbench layout compact and table aligned', function () {
    $header = view('livewire.dashboard.partials.recipe-workbench.header')->render();
    $settings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isCosmeticWorkbench' => true,
    ])->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($header)
        ->toContain('manufacturingModeLabel')
        ->toContain('exposureModeLabel')
        ->toContain('Regime ${regulatoryRegime.toUpperCase()}')
        ->not->toContain('mt-4 flex flex-wrap gap-2 border-t')
        ->and($settings)
        ->toContain('lg:grid-cols-2 xl:grid-cols-4')
        ->toContain('Batch weight')
        ->toContain('Entry mode')
        ->toContain('Exposure')
        ->toContain('IFRA context')
        ->not->toContain('Product type')
        ->and($cosmeticFormula)
        ->toContain('Formula total</div>')
        ->toContain('cosmeticFormulaWeightTotal()')
        ->not->toContain('<p class="text-sm text-[var(--color-ink-soft)]">')
        ->and(substr_count($cosmeticFormula, 'Drop here'))->toBeGreaterThanOrEqual(2);
});

it('reloads weight mode cosmetic drafts without losing saved item weights', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');

    $draftVersion = app(RecipeWorkbenchService::class)->saveDraft(
        $user,
        $cosmeticFamily,
        [
            ...cosmeticDraftPayload($productType, [
                'phase_a' => [
                    cosmeticPayloadRow($aqua, percentage: 100, weight: 100),
                ],
            ]),
            'oil_weight' => 100,
            'editing_mode' => 'weight',
        ],
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $draft = app(RecipeWorkbenchService::class)->draftPayload($recipe);

    expect($draft['editMode'])->toBe('weight')
        ->and($draft['phaseItems']['phase_a'][0]['weight'])->toBe(100.0);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount($recipe);
    $result = $component->saveDraft(
        app(RecipeWorkbenchDraftPayloadMapper::class)->toSavePayload($draft),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    $item = RecipeItem::withoutGlobalScopes()
        ->whereHas('recipeVersion', fn ($query) => $query
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true))
        ->firstOrFail();

    expect($result['ok'])->toBeTrue()
        ->and((float) $item->weight)->toBe(100.0)
        ->and((float) $item->percentage)->toBe(100.0);
});

it('keeps cosmetic phases when duplicating and restoring saved formulas', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');
    $glycerin = cosmeticIngredient('Glycerin', 'GLYCERIN');
    $service = app(RecipeWorkbenchService::class);

    $savedVersion = $service->saveRecipe(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayloadWithPhases($productType, [
            ['key' => 'phase_a', 'name' => 'Phase A'],
            ['key' => 'cool_down', 'name' => 'Cool Down'],
        ], [
            'phase_a' => [cosmeticPayloadRow($aqua, percentage: 70, weight: 70)],
            'cool_down' => [cosmeticPayloadRow($glycerin, percentage: 30, weight: 30)],
        ]),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($savedVersion->recipe_id);
    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->firstOrFail();

    $duplicate = $service->duplicateRecipe($user, $recipe);
    $service->useVersionAsDraft($user, $recipe, $publishedVersion->id);

    expect(RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $duplicate->id)
        ->pluck('slug')
        ->all())->toContain('phase_a', 'cool_down');

    expect(RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->value('id'))
        ->pluck('slug')
        ->all())->toContain('phase_a', 'cool_down');
});

it('rejects completed cosmetic formulas with valued rows that have no ingredient', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');

    expect(fn () => app(RecipeWorkbenchService::class)->saveRecipe(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($aqua, percentage: 80, weight: 80),
                [
                    'ingredient_id' => null,
                    'percentage' => 20,
                    'weight' => 20,
                    'note' => null,
                ],
            ],
        ]),
    ))->toThrow(ValidationException::class, 'Choose an ingredient for every cosmetic row with a percentage or weight.');
});

it('redirects new cosmetic drafts to the recipe URL after first save', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount(null, 'cosmetic', 'cream-lotion');
    $result = $component->saveDraft(
        cosmeticDraftPayload($productType, [
            'phase_a' => [
                cosmeticPayloadRow($aqua, percentage: 100, weight: 100),
            ],
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    $recipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Daily Moisturizer')
        ->firstOrFail();

    expect($result['ok'])->toBeTrue()
        ->and($result['redirect'])->toBe(route('recipes.edit', $recipe->id));
});

it('renders saved cosmetic phases in the read-only recipe view', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $aqua = cosmeticIngredient('Water', 'AQUA');
    $glycerin = cosmeticIngredient('Glycerin', 'GLYCERIN');

    $savedVersion = app(RecipeWorkbenchService::class)->saveRecipe(
        $user,
        $cosmeticFamily,
        cosmeticDraftPayloadWithPhases($productType, [
            ['key' => 'phase_a', 'name' => 'Phase A'],
            ['key' => 'cool_down', 'name' => 'Cool Down'],
        ], [
            'phase_a' => [cosmeticPayloadRow($aqua, percentage: 70, weight: 70)],
            'cool_down' => [cosmeticPayloadRow($glycerin, percentage: 30, weight: 30)],
        ]),
    );

    $this->actingAs($user)
        ->get(route('recipes.saved', $savedVersion->recipe_id))
        ->assertSuccessful()
        ->assertSee('Phase A')
        ->assertSee('Cool Down')
        ->assertSee('Water')
        ->assertSee('Glycerin');
});

function cosmeticIngredient(string $name, string $inciName): Ingredient
{
    return Ingredient::factory()->create([
        'display_name' => $name,
        'inci_name' => $inciName,
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
 * @return array<string, mixed>
 */
function cosmeticDraftPayload(?ProductType $productType, array $phaseItems): array
{
    return [
        'name' => 'Daily Moisturizer',
        'product_type_id' => $productType?->id,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => collect($phaseItems)
            ->keys()
            ->map(fn (string $phaseKey, int $index): array => [
                'key' => $phaseKey,
                'name' => 'Phase '.chr(65 + $index),
            ])
            ->values()
            ->all(),
        'phase_items' => $phaseItems,
    ];
}

/**
 * @param  array<int, array{key: string, name: string}>  $phases
 * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
 * @return array<string, mixed>
 */
function cosmeticDraftPayloadWithPhases(?ProductType $productType, array $phases, array $phaseItems): array
{
    return [
        ...cosmeticDraftPayload($productType, $phaseItems),
        'phases' => $phases,
    ];
}

/**
 * @return array<string, mixed>
 */
function cosmeticPayloadRow(Ingredient $ingredient, float $percentage, float $weight): array
{
    return [
        'ingredient_id' => $ingredient->id,
        'percentage' => $percentage,
        'weight' => $weight,
        'note' => null,
    ];
}
