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
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionDetailPresenter;
use App\Services\Production\ProductionOutputReconciliation;
use App\Services\Production\ProductionRunPrintDataBuilder;
use App\Services\RecipeWorkbenchService;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Published reference liquid soap (1000 g oil at -3% superfat, citric acid
 * as a 20% solution, 2% lavender of the final batch) planned at 2000 g oil.
 *
 * @return array{owner: User, workspace: Workspace, production: ProductionRun}
 */
function liquidSoapProduction(float $kohSapValue = 0.19, float $citricAcidWeight = 6.5062): array
{
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
        'oil_unit' => 'g',
        'oil_weight' => 1000,
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
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => $citricAcidWeight / 10, 'weight' => $citricAcidWeight, 'note' => null]],
            'additives' => [],
            'fragrance' => [['ingredient_id' => $lavender->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
        ],
    ]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $owner,
        workspace: $workspace,
        recipe: Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id),
        basisInputValue: '2000',
        basisInputUnit: 'g',
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
        'Paste',
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
        ->and($process['neutralizer']['weight'])->toEqualWithDelta(3.2531, 0.0001);
});
