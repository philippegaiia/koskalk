<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\ProductionBasisKind;
use App\Enums\Visibility;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionFormulaLine;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionFormulaSnapshotBuilder;
use App\Services\Production\ProductionRequirementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
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

it('stores nullable actual mass for calculated formula lines at canonical precision', function (): void {
    expect(Schema::hasColumn('production_formula_lines', 'actual_mass_grams'))->toBeTrue();

    $line = ProductionFormulaLine::factory()->create([
        'actual_mass_grams' => '283.125',
    ]);

    expect($line->actual_mass_grams)->toBe('283.125000000');
});

it('builds a complete NaOH soap formula snapshot scaled to the production basis', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    $fixture = [...$fixture, ...addSoapFormula($fixture)];

    $snapshot = buildFormulaSnapshot($fixture, '14000.000000000', 100);

    $ingredientLines = $snapshot['lines']->where('component', 'ingredient')->values();

    expect($ingredientLines->pluck('planned_mass_grams')->all())
        ->toBe(['10500.000000000', '3500.000000000', '280.000000000', '140.000000000'])
        ->and($ingredientLines->pluck('subject_name_snapshot')->all())
        ->toBe(['Olive oil', 'Coconut oil', 'Kaolin clay', 'Lavender'])
        ->and($ingredientLines->pluck('sort_order')->all())->toBe([1, 2, 3, 4])
        ->and($ingredientLines->pluck('basis_percentage_snapshot')->all())
        ->toBe(['75.000000000', '25.000000000', '2.000000000', '1.000000000'])
        ->and($ingredientLines->first()['ingredient_id'])->toBe($fixture['olive']->id)
        ->and($ingredientLines->first()['phase_key_snapshot'])->toBe('saponified_oils')
        ->and($ingredientLines->first()['phase_name_snapshot'])->toBe('Saponified Oils')
        ->and($ingredientLines->first()['note_snapshot'])->toBeNull()
        ->and($ingredientLines->get(1)['note_snapshot'])->toBe('Melt gently before mixing')
        ->and($snapshot['lines']->where('component', 'naoh')->count())->toBe(1)
        ->and($snapshot['lines']->where('component', 'koh')->count())->toBe(0)
        ->and($snapshot['lines']->where('component', 'water')->count())->toBe(1)
        ->and((float) $snapshot['lines']->where('component', 'naoh')->first()['planned_mass_grams'])->toBeGreaterThan(0)
        ->and((float) $snapshot['lines']->where('component', 'water')->first()['planned_mass_grams'])->toBeGreaterThan(0)
        ->and($snapshot['lines']->where('component', 'naoh')->first()['phase_key_snapshot'])->toBe('lye_water')
        ->and($snapshot['lines']->where('component', 'water')->first()['phase_key_snapshot'])->toBe('lye_water')
        ->and($snapshot['lines']->where('component', 'naoh')->first()['ingredient_id'])
        ->toBe(Ingredient::query()->where('catalog_key', 'CH1')->sole()->id);
});

it('builds KOH soap snapshots with KOH and water but no NaOH line', function (): void {
    $fixture = productionFormulaSnapshotFixture('koh');
    addSoapFormula($fixture);

    $snapshot = buildFormulaSnapshot($fixture, '14000.000000000', 100);

    $kohLine = $snapshot['lines']->where('component', 'koh')->first();

    expect($snapshot['lines']->where('component', 'naoh')->count())->toBe(0)
        ->and($snapshot['lines']->where('component', 'water')->count())->toBe(1)
        ->and((float) $kohLine['planned_mass_grams'])->toBeGreaterThan(0)
        ->and($kohLine['ingredient_id'])
        ->toBe(Ingredient::query()->where('catalog_key', 'CH3')->sole()->id)
        ->and($kohLine['subject_name_snapshot'])->toBe('Potassium hydroxide (KOH 90%)')
        ->and($snapshot['context']['lye_type'])->toBe('koh')
        ->and($snapshot['context']['koh_purity_percentage'])->toBe(90.0);
});

it('raises the frozen KOH purity label and lowers planned mass at one hundred percent', function (): void {
    $ninetyFixture = productionFormulaSnapshotFixture('koh');
    addSoapFormula($ninetyFixture);
    $hundredFixture = productionFormulaSnapshotFixture('koh');
    addSoapFormula($hundredFixture);
    $context = $hundredFixture['version']->calculation_context;
    $context['koh_purity_percentage'] = 100;
    $hundredFixture['version']->forceFill(['calculation_context' => $context])->save();

    $atNinety = buildFormulaSnapshot($ninetyFixture, '14000.000000000', 100);
    $atHundred = buildFormulaSnapshot($hundredFixture, '14000.000000000', 100);

    $kohAtNinety = $atNinety['lines']->where('component', 'koh')->first();
    $kohAtHundred = $atHundred['lines']->where('component', 'koh')->first();

    expect($kohAtHundred['subject_name_snapshot'])->toBe('Potassium hydroxide (KOH 100%)')
        ->and($atHundred['context']['koh_purity_percentage'])->toBe(100.0)
        ->and($kohAtHundred['ingredient_id'])->toBe($kohAtNinety['ingredient_id'])
        ->and((float) $kohAtHundred['planned_mass_grams'])
        ->toBeLessThan((float) $kohAtNinety['planned_mass_grams']);
});

it('preserves fractional KOH purity in the frozen production label', function (): void {
    $fixture = productionFormulaSnapshotFixture('koh');
    addSoapFormula($fixture);
    $context = $fixture['version']->calculation_context;
    $context['koh_purity_percentage'] = 90.5;
    $fixture['version']->forceFill(['calculation_context' => $context])->save();

    $snapshot = buildFormulaSnapshot($fixture, '14000.000000000', 100);
    $kohLine = $snapshot['lines']->where('component', 'koh')->first();

    expect($kohLine['subject_name_snapshot'])->toBe('Potassium hydroxide (KOH 90.5%)')
        ->and($snapshot['context']['koh_purity_percentage'])->toBe(90.5);
});

it('builds dual-lye soap snapshots with NaOH, KOH, and water lines', function (): void {
    $fixture = productionFormulaSnapshotFixture('dual');
    addSoapFormula($fixture);

    $snapshot = buildFormulaSnapshot($fixture, '14000.000000000', 100);

    expect($snapshot['lines']->where('component', 'naoh')->count())->toBe(1)
        ->and($snapshot['lines']->where('component', 'koh')->count())->toBe(1)
        ->and($snapshot['lines']->where('component', 'water')->count())->toBe(1)
        ->and((float) $snapshot['lines']->where('component', 'naoh')->first()['planned_mass_grams'])->toBeGreaterThan(0)
        ->and((float) $snapshot['lines']->where('component', 'koh')->first()['planned_mass_grams'])->toBeGreaterThan(0)
        ->and($snapshot['lines']->where('component', 'naoh')->first()['ingredient_id'])
        ->toBe(Ingredient::query()->where('catalog_key', 'CH1')->sole()->id)
        ->and($snapshot['lines']->where('component', 'koh')->first()['ingredient_id'])
        ->toBe(Ingredient::query()->where('catalog_key', 'CH3')->sole()->id)
        ->and($snapshot['lines']->where('component', 'koh')->first()['subject_name_snapshot'])
        ->toBe('Potassium hydroxide (KOH 90%)')
        ->and($snapshot['context']['lye_type'])->toBe('dual')
        ->and($snapshot['context']['koh_purity_percentage'])->toBe(90.0);
});

it('rejects a soap snapshot when recalculation produces no water', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    addSoapFormula($fixture);
    $fixture['version']->forceFill([
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 0],
    ])->save();

    expect(fn (): array => buildFormulaSnapshot($fixture, '14000.000000000', 100))
        ->toThrow(ValidationException::class);
});

it('copies every cosmetic phase ingredient without calculated lye or water', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh', calculationBasis: 'total_formula');
    $ingredient = Ingredient::factory()->create(['display_name' => 'Emulsifier']);
    $phase = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Phase A',
        'slug' => 'phase_a',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '12.5000',
        'weight' => null,
    ]);

    $snapshot = buildFormulaSnapshot($fixture, '8000.000000000', 200);

    expect($snapshot['lines']->count())->toBe(1)
        ->and($snapshot['lines']->first()['planned_mass_grams'])->toBe('1000.000000000')
        ->and($snapshot['lines']->where('component', 'naoh')->count())->toBe(0)
        ->and($snapshot['lines']->where('component', 'koh')->count())->toBe(0)
        ->and($snapshot['lines']->where('component', 'water')->count())->toBe(0)
        ->and($snapshot['context']['calculation_basis'])->toBe('total_formula')
        ->and($snapshot['context']['lye_type'])->toBeNull()
        ->and($snapshot['context']['superfat_percentage'])->toBeNull()
        ->and($snapshot['context']['water_mode'])->toBeNull()
        ->and($snapshot['context']['water_value'])->toBeNull();
});

it('stores only stable formula calculation facts in the context snapshot', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    addSoapFormula($fixture);

    $snapshot = buildFormulaSnapshot($fixture, '14000.000000000', 100);

    expect($snapshot['context'])->toBe([
        'calculation_basis' => 'initial_oils',
        'lye_type' => 'naoh',
        'superfat_percentage' => 5.0,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38.0,
    ]);
});

it('scales selected lye liquids from fresh mass and keeps only remaining water as calculated', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    addSoapFormula($fixture);
    $hydrosol = Ingredient::factory()->create(['display_name' => 'Rose hydrosol']);
    $lyeWater = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $fixture['version']->id)
        ->where('slug', 'lye_water')
        ->firstOrFail();
    RecipeItem::factory()->for($fixture['version'])->for($lyeWater, 'recipePhase')->for($hydrosol)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '25.0000',
        'weight' => '95.0000',
    ]);

    $snapshot = buildFormulaSnapshot($fixture, '2000.000000000', 20);
    $hydrosolLine = $snapshot['lines']->firstWhere('ingredient_id', $hydrosol->id);
    $waterLine = $snapshot['lines']->firstWhere('component', 'water');

    expect($hydrosolLine['planned_mass_grams'])->toBe('190.000000000')
        ->and($hydrosolLine['phase_key_snapshot'])->toBe('lye_water')
        ->and($waterLine['planned_mass_grams'])->toBe('570.000000000');
});

it('copies packaging plan notes into packaging requirement snapshots', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    $packaging = PackagingItem::factory()->for($fixture['workspace'])->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $fixture['version']->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'notes' => 'Folded inserts ship flat',
        'position' => 1,
    ]);

    $requirements = app(ProductionRequirementBuilder::class)->build(
        version: $fixture['version'],
        basisKind: ProductionBasisKind::OilMass,
        basisQuantityGrams: '14000.000000000',
        expectedUnits: 100,
        recipe: $fixture['recipe'],
    );

    expect($requirements->where('kind', 'packaging')->first()['note_snapshot'])
        ->toBe('Folded inserts ship flat');
});

it('rejects a soap formula whose calculation cannot be rebuilt', function (): void {
    $fixture = productionFormulaSnapshotFixture('naoh');
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $phase = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '100.0000',
        'weight' => null,
    ]);

    $requirements = app(ProductionRequirementBuilder::class)->build(
        version: $fixture['version'],
        basisKind: ProductionBasisKind::OilMass,
        basisQuantityGrams: '14000.000000000',
        expectedUnits: 100,
        recipe: $fixture['recipe'],
    );

    expect(fn (): array => app(ProductionFormulaSnapshotBuilder::class)->build(
        recipe: $fixture['recipe'],
        version: $fixture['version'],
        basisQuantityGrams: '14000.000000000',
        requirements: $requirements,
    ))->toThrow(ValidationException::class);
});

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 * @return array{context: array<string, mixed>, lines: Collection<int, array<string, mixed>>}
 */
function buildFormulaSnapshot(array $fixture, string $basisQuantityGrams, int $expectedUnits): array
{
    $basisKind = $fixture['recipe']->productFamily?->calculation_basis === 'total_formula'
        ? ProductionBasisKind::TotalFormulaMass
        : ProductionBasisKind::OilMass;
    $requirements = app(ProductionRequirementBuilder::class)->build(
        version: $fixture['version'],
        basisKind: $basisKind,
        basisQuantityGrams: $basisQuantityGrams,
        expectedUnits: $expectedUnits,
        recipe: $fixture['recipe'],
    );

    return app(ProductionFormulaSnapshotBuilder::class)->build(
        recipe: $fixture['recipe'],
        version: $fixture['version'],
        basisQuantityGrams: $basisQuantityGrams,
        requirements: $requirements,
    );
}

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionFormulaSnapshotFixture(string $lyeType = 'naoh', string $calculationBasis = 'initial_oils'): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => ($calculationBasis === 'total_formula' ? 'cosmetic-' : 'soap-').fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => $calculationBasis,
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

    return compact('owner', 'workspace', 'recipe', 'version');
}

/**
 * @param  array{version: RecipeVersion, workspace: Workspace}  $fixture
 * @return array{olive: Ingredient, coconut: Ingredient, additive: Ingredient, fragrance: Ingredient}
 */
function addSoapFormula(array $fixture): array
{
    $oleic = FattyAcid::query()->firstWhere('key', 'oleic')
        ?? FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $lauric = FattyAcid::query()->firstWhere('key', 'lauric')
        ?? FattyAcid::factory()->create(['key' => 'lauric', 'name' => 'Lauric']);

    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $olive->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 100,
    ]);

    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $coconut->id, 'koh_sap_value' => 0.19]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $coconut->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 100,
    ]);

    $additive = Ingredient::factory()->create(['display_name' => 'Kaolin clay']);
    $fragrance = Ingredient::factory()->create(['display_name' => 'Lavender']);

    $oils = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    $lyeWater = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Lye Water',
        'slug' => 'lye_water',
        'sort_order' => 2,
    ]);
    $additives = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Additives',
        'slug' => 'additives',
        'sort_order' => 3,
    ]);
    $fragrancePhase = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Fragrance And Aromatics',
        'slug' => 'fragrance',
        'sort_order' => 4,
    ]);

    RecipeItem::factory()->for($fixture['version'])->for($oils, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '75.0000',
        'weight' => null,
        'note' => null,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($oils, 'recipePhase')->for($coconut)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 2,
        'percentage' => '25.0000',
        'weight' => null,
        'note' => 'Melt gently before mixing',
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($additives, 'recipePhase')->for($additive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '2.0000',
        'weight' => null,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($fragrancePhase, 'recipePhase')->for($fragrance)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '1.0000',
        'weight' => null,
    ]);

    return compact('olive', 'coconut', 'additive', 'fragrance');
}
