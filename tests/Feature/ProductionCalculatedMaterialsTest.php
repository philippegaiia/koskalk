<?php

use App\Actions\Production\CreateProductionDraft;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\ProductionFormulaComponent;
use App\Enums\Visibility;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionAvailabilityPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'CH1' => IngredientSubcategory::SodiumHydroxide,
        'CH3' => IngredientSubcategory::PotassiumHydroxide,
    ] as $catalogKey => $subcategory) {
        Ingredient::factory()->create([
            'catalog_key' => $catalogKey,
            'category' => IngredientCategory::SoapmakingAlkalis,
            'subcategory' => $subcategory,
            'display_name' => $subcategory === IngredientSubcategory::SodiumHydroxide
                ? 'Sodium hydroxide'
                : 'Potassium hydroxide',
            'is_active' => true,
        ]);
    }
});

it('turns calculated NaOH into a stock requirement while leaving water non-stock', function (): void {
    $fixture = productionCalculatedMaterialsFixture();

    $production = createCalculatedMaterialProduction($fixture, 'calculated-naoh');
    $naohLine = $production->formulaLines->firstWhere('component', ProductionFormulaComponent::Naoh);
    $waterLine = $production->formulaLines->firstWhere('component', ProductionFormulaComponent::Water);
    $naoh = Ingredient::query()->where('catalog_key', 'CH1')->sole();

    expect($naohLine)->not->toBeNull()
        ->and($naohLine?->ingredient_id)->toBe($naoh->id)
        ->and($production->requirements->where('ingredient_id', $naoh->id)->count())->toBe(1)
        ->and($production->requirements->firstWhere('ingredient_id', $naoh->id)?->required_mass_grams)
        ->toBe($naohLine?->planned_mass_grams)
        ->and($waterLine?->ingredient_id)->toBeNull()
        ->and($production->requirements->contains(fn (ProductionRequirement $requirement): bool => $requirement->subject_name_snapshot === 'Water'))
        ->toBeFalse();
});

it('tracks KOH and dual-lye calculations against their platform materials', function (): void {
    $kohFixture = productionCalculatedMaterialsFixture('koh');
    $kohProduction = createCalculatedMaterialProduction($kohFixture, 'calculated-koh');
    $kohLine = $kohProduction->formulaLines->firstWhere('component', ProductionFormulaComponent::Koh);
    $koh = Ingredient::query()->where('catalog_key', 'CH3')->sole();

    expect($kohLine?->ingredient_id)->toBe($koh->id)
        ->and($kohLine?->subject_name_snapshot)->toBe('Potassium hydroxide (KOH 90%)')
        ->and($kohProduction->requirements->firstWhere('ingredient_id', $koh->id)?->required_mass_grams)
        ->toBe($kohLine?->planned_mass_grams)
        ->and($kohProduction->requirements->firstWhere('ingredient_id', $koh->id)?->subject_name_snapshot)
        ->toBe('Potassium hydroxide (KOH 90%)');

    $dualFixture = productionCalculatedMaterialsFixture('dual');
    $dualProduction = createCalculatedMaterialProduction($dualFixture, 'calculated-dual');
    $naoh = Ingredient::query()->where('catalog_key', 'CH1')->sole();
    $dualKoh = Ingredient::query()->where('catalog_key', 'CH3')->sole();

    expect($dualProduction->requirements->whereIn('ingredient_id', [$naoh->id, $dualKoh->id])->count())
        ->toBe(2);
});

it('previews the calculated KOH requirement before any production exists', function (): void {
    $fixture = productionCalculatedMaterialsFixture('koh');

    $preview = app(ProductionAvailabilityPreview::class)->for(
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: 'kg',
        expectedUnits: '288',
        taskSet: null,
        plannedFor: null,
    );

    expect(collect($preview['requirements'])->pluck('subject_name')->all())
        ->toContain('Potassium hydroxide (KOH 90%)')
        ->and(ProductionRun::query()->count())->toBe(0);
});

it('freezes workspace material codes in production requirements and previews', function (): void {
    $fixture = productionCalculatedMaterialsFixture('koh');
    $olive = Ingredient::query()->where('display_name', 'Olive oil')->sole();
    $koh = Ingredient::query()->where('catalog_key', 'CH3')->sole();
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'ingredient_id' => $olive->id,
        'material_code' => 'RM-OLIVE',
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'ingredient_id' => $koh->id,
        'material_code' => 'LYE-KOH90',
    ]);

    $preview = app(ProductionAvailabilityPreview::class)->for(
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: 'kg',
        expectedUnits: '288',
        taskSet: null,
        plannedFor: null,
    );

    expect(collect($preview['requirements'])->firstWhere('subject_name', 'Olive oil')['material_code'])
        ->toBe('RM-OLIVE')
        ->and(collect($preview['requirements'])->firstWhere('subject_name', 'Potassium hydroxide (KOH 90%)')['material_code'])
        ->toBe('LYE-KOH90');

    $production = createCalculatedMaterialProduction($fixture, 'material-code-snapshot');
    $kohRequirement = $production->requirements->firstWhere('ingredient_id', $koh->id);

    expect($kohRequirement?->material_code_snapshot)->toBe('LYE-KOH90');

    WorkspaceIngredientCode::query()
        ->where('workspace_id', $fixture['workspace']->id)
        ->where('ingredient_id', $koh->id)
        ->update(['material_code' => 'LYE-KOH100']);

    expect($kohRequirement->fresh()->material_code_snapshot)->toBe('LYE-KOH90');
});

it('surfaces a missing canonical alkali in the production preview', function (): void {
    $fixture = productionCalculatedMaterialsFixture('koh');
    Ingredient::query()->where('catalog_key', 'CH3')->delete();

    $preview = app(ProductionAvailabilityPreview::class)->for(
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: 'kg',
        expectedUnits: '288',
        taskSet: null,
        plannedFor: null,
    );

    expect($preview['error'])->toBe(__('ingredients.alkalis.validation.canonical_missing', ['key' => 'CH3']))
        ->and($preview['requirements'])->toBeEmpty()
        ->and(ProductionRun::query()->count())->toBe(0);
});

it('renders production preview errors to the user', function (): void {
    $view = file_get_contents(resource_path('views/livewire/production-bench/production/production-create.blade.php'));

    expect($view)
        ->toContain(<<<'BLADE'
@if ($preview['error'])
BLADE)
        ->toContain(<<<'BLADE'
{{ $preview['error'] }}
BLADE);
});

it('does not create calculated lye requirements for total-formula productions', function (): void {
    $fixture = productionCalculatedMaterialsFixture('naoh', 'total_formula');

    $production = createCalculatedMaterialProduction($fixture, 'calculated-cosmetic');

    expect($production->formulaLines->whereIn('component', [
        ProductionFormulaComponent::Naoh,
        ProductionFormulaComponent::Koh,
        ProductionFormulaComponent::Water,
    ]))->toBeEmpty()
        ->and($production->requirements->where('kind', 'ingredient'))->toHaveCount(1);
});

it('fails the whole production creation when the calculated platform material is missing', function (): void {
    $fixture = productionCalculatedMaterialsFixture();
    Ingredient::query()->where('catalog_key', 'CH1')->delete();

    expect(fn (): ProductionRun => createCalculatedMaterialProduction($fixture, 'missing-calculated-naoh'))
        ->toThrow(ValidationException::class);

    expect(ProductionRun::query()->count())->toBe(0);
});

it('fails KOH production when the KOH platform material is missing', function (): void {
    $fixture = productionCalculatedMaterialsFixture('koh');
    Ingredient::query()->where('catalog_key', 'CH3')->delete();

    expect(fn (): ProductionRun => createCalculatedMaterialProduction($fixture, 'missing-calculated-koh'))
        ->toThrow(ValidationException::class);

    expect(ProductionRun::query()->count())->toBe(0);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionCalculatedMaterialsFixture(
    string $lyeType = 'naoh',
    string $calculationBasis = 'initial_oils',
): array {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'calculation_basis' => $calculationBasis,
        'slug' => fake()->unique()->slug(),
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'manufacturing_mode' => $calculationBasis === 'total_formula' ? 'blend_only' : 'saponify_in_formula',
        'batch_mass_grams' => '1000.000000000',
        'batch_unit' => 'g',
        'calculation_context' => [
            'editing_mode' => 'percentage',
            'lye_type' => $lyeType,
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'superfat' => 5,
            'oil_weight' => 1000,
            'oil_unit' => 'g',
            'mass_grams' => 1000,
            'totals' => [],
        ],
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 38],
    ]);

    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);

    if ($calculationBasis === 'initial_oils') {
        $fattyAcid = FattyAcid::factory()->create([
            'key' => fake()->unique()->slug(),
            'name' => 'Oleic',
        ]);
        IngredientSapProfile::factory()->create([
            'ingredient_id' => $ingredient->id,
            'koh_sap_value' => 0.188,
        ]);
        IngredientFattyAcid::factory()->create([
            'ingredient_id' => $ingredient->id,
            'fatty_acid_id' => $fattyAcid->id,
            'percentage' => 100,
        ]);
    }

    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $calculationBasis === 'initial_oils' ? 'Saponified Oils' : 'Phase A',
        'slug' => $calculationBasis === 'initial_oils' ? 'saponified_oils' : 'phase_a',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '100.0000',
        'weight' => null,
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function createCalculatedMaterialProduction(array $fixture, string $idempotencyKey): ProductionRun
{
    return app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: 'kg',
        expectedUnits: 288,
        idempotencyKey: $idempotencyKey,
    );
}
