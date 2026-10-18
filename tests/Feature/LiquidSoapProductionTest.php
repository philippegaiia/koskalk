<?php

use App\Actions\Production\CreateProductionDraft;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Enums\ProductionFormulaComponent;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\FlashProductionSimulator;
use App\Services\Production\ProductionDetailPresenter;
use App\Services\Production\ProductionOutputReconciliation;
use App\Services\Production\ProductionRunPrintDataBuilder;
use App\Services\RecipeWorkbenchService;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Published reference liquid soap (1000 g oil at -3% superfat, citric acid
 * as a 20% solution, 2% lavender of the final batch) planned at 2000 g oil.
 *
 * @return array{owner: User, workspace: Workspace, production: ProductionRun}
 */
function liquidSoapProduction(float $kohSapValue = 0.19, float $citricAcidWeight = 6.5062, string $unit = 'g', string $basis = '2000', ?float $oilWeight = null, array $finalDilutionLiquids = [], ?Closure $beforePlanning = null): array
{
    $oilWeight ??= $unit === 'kg' ? 1 : 1000;
    Ingredient::factory()->create(['catalog_key' => 'CH3', 'category' => IngredientCategory::SoapmakingAlkalis, 'subcategory' => IngredientSubcategory::PotassiumHydroxide, 'display_name' => 'Potassium hydroxide', 'is_active' => true]);
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'display_name' => 'Olive Oil', 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => $kohSapValue]);
    $citricAcid = Ingredient::factory()->create(['category' => IngredientCategory::PhAdjustersBuffers, 'display_name' => 'Citric Acid', 'owner_type' => null, 'owner_id' => null, 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $lavender = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'display_name' => 'Lavender Oil', 'is_active' => true]);
    $version = app(RecipeWorkbenchService::class)->publish($owner, $family, [
        'name' => 'Liquid castile',
        'product_type_id' => $productType->id,
        'oil_unit' => $unit,
        'oil_weight' => $oilWeight,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'none',
        'editing_mode' => 'percentage',
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'lye_concentration',
        'water_value' => 28,
        'superfat' => -3,
        'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => $oilWeight, 'note' => null]],
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => $citricAcidWeight * 100 / $oilWeight, 'weight' => $citricAcidWeight, 'note' => null]],
            'additives' => [],
            'fragrance' => [['ingredient_id' => $lavender->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
            'final_dilution' => $finalDilutionLiquids,
        ],
    ]);

    if ($beforePlanning !== null) {
        $beforePlanning();
    }

    $production = app(CreateProductionDraft::class)->handle(
        actor: $owner,
        workspace: $workspace,
        recipe: Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id),
        basisInputValue: $basis,
        basisInputUnit: $unit,
        expectedUnits: 19,
        idempotencyKey: 'liquid-castile',
    );

    return compact('owner', 'workspace', 'production');
}

it('plans the neutralizer, every dilution water and the process steps of a liquid soap batch', function (): void {
    $production = liquidSoapProduction()['production'];
    $lines = $production->formulaLines->sortBy('sort_order')->values();
    $water = $lines->where('component', ProductionFormulaComponent::Water)->mapWithKeys(fn ($line): array => [$line->phase_key_snapshot => (float) $line->planned_mass_grams]);
    $requirements = $production->requirements->mapWithKeys(fn ($requirement): array => [$requirement->phase_key_snapshot.':'.$requirement->subject_name_snapshot => (float) $requirement->required_mass_grams]);
    $context = $production->formula_context_snapshot;

    expect($requirements->only(['neutralization:Citric Acid', 'fragrance:Lavender Oil'])->all())->toEqualWithDelta(['neutralization:Citric Acid' => 13.0124, 'fragrance:Lavender Oil' => 190.4], 0.00001)
        ->and($requirements->keys()->all())->toContain('lye_water:Potassium hydroxide (KOH 90%)')
        ->and($water->keys()->all())->toBe(['lye_water', 'first_dilution', 'neutralization', 'final_dilution'])
        ->and($water['first_dilution'])->toEqualWithDelta(3358.654, 0.001)
        ->and($water['neutralization'])->toEqualWithDelta(52.0496, 0.0001)
        ->and($context['soap_process'])->toBe('liquid_paste')
        ->and((float) $context['liquid_process']['final_percentage'])->toEqualWithDelta(476.0, 0.0001)
        ->and($context['liquid_process']['neutralizer']['name'])->toBe('Citric Acid')
        ->and($context['liquid_process']['warnings'])->toContain('neutralizer_amount_is_theoretical')
        // Every weighed line adds up to the final diluted batch, not a cured bar.
        ->and($lines->sum(fn ($line): float => (float) $line->planned_mass_grams))->toEqualWithDelta(9520.0, 0.01)
        ->and(app(ProductionOutputReconciliation::class)->forProduction($production->forceFill(['actual_output_mass_grams' => '9500'])))
        ->toMatchArray(['unit' => 'g', 'planned' => '9520']);
});

it('plans a neutralizer saved at its theoretical amount from its saved weight', function (): void {
    // 6.8486 g is stored as 0.6849% of the oils: scaling that rounded share to
    // 2000 g would give 13.698 g, more than the 13.6972 g the free KOH takes.
    $production = liquidSoapProduction(kohSapValue: 0.20, citricAcidWeight: 6.8486)['production'];
    $acid = $production->requirements->firstWhere('phase_key_snapshot', 'neutralization');
    $acidLine = $production->formulaLines->firstWhere('phase_key_snapshot', 'neutralization');

    expect($acid->required_mass_grams)->toBe('13.697200000')
        ->and($acidLine->planned_mass_grams)->toBe('13.697200000')
        ->and($production->formula_context_snapshot['liquid_process']['neutralizer']['percentage'])->toBe('0.684860000');
});

it('plans a neutralizer from its most precise saved quantity', function (): void {
    // 0.00719103 kg is stored as 0.0072 kg but 0.7191% of the oils: at 10 kg
    // the weight would give 72 g, more than the 71.9103 g the free KOH takes.
    $production = liquidSoapProduction(kohSapValue: 0.21, citricAcidWeight: 0.00719103, unit: 'kg', basis: '10')['production'];

    expect($production->requirements->firstWhere('phase_key_snapshot', 'neutralization')->required_mass_grams)->toBe('71.910000000')
        ->and($production->formulaLines->firstWhere('phase_key_snapshot', 'neutralization')->planned_mass_grams)->toBe('71.910000000');
});

it('plans the theoretical neutralizer amount when only the saved rounding exceeds it', function (): void {
    // Authored at 10 g oils, the theoretical 0.0684864 g is stored as 0.6849%
    // of the oils: at 10 kg that overshoots the free KOH by 0.0036 g, which
    // the batch absorbs by taking the theoretical amount.
    $production = liquidSoapProduction(kohSapValue: 0.20, citricAcidWeight: 0.0684864, basis: '10000', oilWeight: 10)['production'];

    expect($production->requirements->firstWhere('phase_key_snapshot', 'neutralization')->required_mass_grams)->toBe('68.486389950')
        ->and($production->formulaLines->firstWhere('phase_key_snapshot', 'neutralization')->planned_mass_grams)->toBe('68.486389950');
});

it('plans the theoretical neutralizer amount when the saved free KOH was rounded up', function (): void {
    // At 10 g oils the free KOH of 0.0600501 g is reported as 0.0601 g, so
    // the recommended 0.0686 g sits 0.0000564 g above what it truly takes;
    // at 10 kg that is 0.0564 g, still within the saved rounding.
    $production = liquidSoapProduction(kohSapValue: 0.200167, citricAcidWeight: 0.0686, basis: '10000', oilWeight: 10)['production'];
    $acid = $production->requirements->firstWhere('phase_key_snapshot', 'neutralization');

    expect((float) $acid->required_mass_grams)->toEqualWithDelta(68.5436, 0.0001)
        ->and($production->formulaLines->firstWhere('phase_key_snapshot', 'neutralization')->planned_mass_grams)->toBe($acid->required_mass_grams);
});

it('plans a resized batch from a dose accepted inside the bench tolerance', function (): void {
    $production = liquidSoapProduction(citricAcidWeight: 6.5082, basis: '10000')['production'];
    $acid = $production->requirements->firstWhere('phase_key_snapshot', 'neutralization');

    expect((float) $acid->required_mass_grams)->toEqualWithDelta(65.0621, 0.0001)
        ->and($production->formulaLines->firstWhere('phase_key_snapshot', 'neutralization')->planned_mass_grams)->toBe($acid->required_mass_grams)
        ->and((float) $production->formulaLines->sum('planned_mass_grams'))->toEqualWithDelta(47600, 0.01);
});

it('refuses a neutralizer that exceeds the free KOH beyond its saved rounding', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'production' => $production] = liquidSoapProduction(kohSapValue: 0.20, citricAcidWeight: 6.8486);
    RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $production->recipe_version_id)->where('ingredient_id', $production->requirements->firstWhere('phase_key_snapshot', 'neutralization')->ingredient_id)
        ->update(['weight' => '6.8600', 'percentage' => '0.6860']);

    expect(fn () => app(CreateProductionDraft::class)->handle(
        actor: $owner,
        workspace: $workspace,
        recipe: Recipe::withoutGlobalScopes()->findOrFail($production->recipe_id),
        basisInputValue: '2000',
        basisInputUnit: 'g',
        expectedUnits: 19,
        idempotencyKey: 'over-neutralized',
    ))->toThrow(ValidationException::class, __('production_bench.production.validation.formula_recalculation_failed'));
});

it('plans dilution liquids as materials and leaves only plain water in the dilution line', function (): void {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    $production = liquidSoapProduction(finalDilutionLiquids: [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]])['production'];
    $liquid = $production->requirements->firstWhere('ingredient_id', $hydrosol->id);
    $liquidLine = $production->formulaLines->firstWhere('ingredient_id', $hydrosol->id);
    $finalWater = $production->formulaLines->first(fn ($line): bool => $line->component === ProductionFormulaComponent::Water && $line->phase_key_snapshot === 'final_dilution');

    expect($liquid->phase_key_snapshot)->toBe('final_dilution')
        ->and($liquidLine->planned_mass_grams)->toBe($liquid->required_mass_grams)
        ->and((float) $liquid->required_mass_grams)->toEqualWithDelta((float) $finalWater->planned_mass_grams * 30 / 70, 0.01)
        ->and($production->formulaLines->sum(fn ($line): float => (float) $line->planned_mass_grams))->toEqualWithDelta(9520.0, 0.01);
});

it('reallocates dilution liquids to the water the batch now takes', function (): void {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    // A small citric acid dose stays below the free KOH once the oil takes less KOH.
    $production = liquidSoapProduction(
        citricAcidWeight: 1.0,
        finalDilutionLiquids: [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]],
        beforePlanning: fn () => IngredientSapProfile::query()->update(['koh_sap_value' => 0.10]),
    )['production'];
    $finalWeight = 2000 * (float) $production->formula_context_snapshot['liquid_process']['final_percentage'] / 100;
    $liquid = $production->requirements->firstWhere('ingredient_id', $hydrosol->id);
    $finalWater = $production->formulaLines->first(fn ($line): bool => $line->component === ProductionFormulaComponent::Water && $line->phase_key_snapshot === 'final_dilution');

    $lavender = $production->requirements->firstWhere('phase_key_snapshot', 'fragrance');

    expect($finalWater)->not->toBeNull()
        ->and((float) $liquid->required_mass_grams)->toEqualWithDelta((float) $finalWater->planned_mass_grams * 30 / 70, 0.01)
        // The fragrance stays at its 2% of the finished batch.
        ->and((float) $lavender->required_mass_grams)->toEqualWithDelta($finalWeight * 0.02, 0.01)
        ->and($production->formulaLines->sum(fn ($line): float => (float) $line->planned_mass_grams))->toEqualWithDelta($finalWeight, 0.01);
});

it('simulates liquid soap materials from the same recalculation used to plan production', function (bool $changeChemistry, string $expectedFragrance) {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    ['workspace' => $workspace, 'production' => $production] = liquidSoapProduction(
        citricAcidWeight: 1.0,
        finalDilutionLiquids: [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]],
        beforePlanning: $changeChemistry ? fn () => IngredientSapProfile::query()->update(['koh_sap_value' => 0.10]) : null,
    );
    $beforeRuns = $workspace->productionRuns()->count();

    $simulation = app(FlashProductionSimulator::class)->simulate($workspace, [[
        'recipe_id' => $production->recipe_id,
        'desired_units' => '20',
        'expected_units_per_batch' => '19',
        'basis_input_value' => '2000',
        'basis_input_unit' => 'g',
    ]]);

    $requirements = collect($simulation['requirements'])->keyBy('ingredient_id');
    $fragrance = $production->requirements->firstWhere('phase_key_snapshot', 'fragrance');
    expect($requirements->get($fragrance->ingredient_id)['required'])->toBe($expectedFragrance)
        ->and($simulation['totals']['whole_batches'])->toBe(2)
        ->and($workspace->productionRuns()->count())->toBe($beforeRuns);

    foreach ($production->requirements->whereNotNull('recipe_item_id') as $requirement) {
        expect($requirements->get($requirement->ingredient_id)['required'])
            ->toBe(bcmul($requirement->required_mass_grams, '2', 9));
    }
})->with([
    'unchanged oil chemistry' => [false, '380.800000000'],
    'revised oil chemistry' => [true, '352.000000000'],
]);

it('lists a liquid soap production in process order on the production page', function (): void {
    $production = liquidSoapProduction()['production'];

    $groups = collect(app(ProductionDetailPresenter::class)->present($production)['materials'])->pluck('group_key')->unique()->values()->all();

    expect($groups)->toBe(['saponified_oils', 'lye_water', 'first_dilution', 'neutralization', 'fragrance', 'final_dilution']);
});

it('prints the liquid soap batch sheet with its process steps sized to the batch', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'production' => $production] = liquidSoapProduction();

    $response = $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $production, 'workspace' => $workspace->public_id]));
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

    $response->assertOk()->assertSeeInOrder([
        'Liquid soap process',
        'Paste', 'Anhydrous soap: 2,380.00 g',
        'First dilution', 'Add water to bring the paste to 35.0% anhydrous soap.', '3,358.65', '6,800.00',
        'Neutralization', 'Add Citric Acid as a 20% solution.', 'Acid: 13.01 g (theoretical 13.01 g)', '65.06',
        'Final dilution', '9,520.00', '25.0%',
        'Lavender Oil', '190.40 g · 2.00%',
    ]);
    expect(collect($document->querySelectorAll('[data-batch-material-group]'))->map(fn ($row): string => $row->getAttribute('data-batch-material-group'))->all())
        ->toBe(['saponified_oils', 'lye_water', 'first_dilution', 'neutralization', 'fragrance', 'final_dilution']);
});

it('sizes the printed liquid soap steps to a rescaled batch', function (): void {
    $production = liquidSoapProduction()['production'];
    $production->forceFill(['basis_quantity_grams' => '500'])->save();

    $process = app(ProductionRunPrintDataBuilder::class)->build($production)['liquid_process'];

    expect(collect($process['steps'])->pluck('mass', 'key')->all())->toEqualWithDelta([
        'paste' => 860.3365,
        'first_dilution' => 1700.0,
        'neutralization' => 1716.2655,
        'final_dilution' => 2380.0,
    ], 0.001)
        ->and($process['neutralizer']['weight'])->toEqualWithDelta(3.2531, 0.0001)
        ->and($process['anhydrous_soap_weight'])->toEqualWithDelta(595.0, 0.0001)
        ->and($process['final_anhydrous_soap_weight'])->toEqualWithDelta(595.0, 0.0001);
});
