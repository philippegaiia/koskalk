<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * Reference paste: 1000 g of an oil with KOH SAP 0.19 at -3% superfat, KOH
 * 90% and a 28% alkali solution, so 5.7 g of KOH is free and the anhydrous
 * soap is 1190 g; at a 25% final dilution the batch weighs 4760 g.
 */

function liquidSoapPreviewOil(): Ingredient
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);

    return $oil;
}

function liquidSoapPreviewCitricAcid(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::PhAdjustersBuffers,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function liquidSoapPreviewPayload(Ingredient $oil, array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Liquid castile',
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
        'liquid_process' => [
            'first_dilution_percentage' => 35,
            'final_dilution_percentage' => 25,
            'neutralizer_solution_percentage' => 20,
        ],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);
}

it('adds the liquid soap process to the preview of liquid soap families only', function (bool $liquid) {
    $family = $liquid
        ? ProductFamily::factory()->liquidSoapPaste()->create()
        : ProductFamily::factory()->create(['slug' => 'soap']);

    $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation(
        liquidSoapPreviewPayload(liquidSoapPreviewOil()),
        null,
        $family,
    );

    expect($calculation['lye']['excess']['koh_weight'])->toBe(5.7)
        ->and(array_key_exists('liquid_process', $calculation))->toBe($liquid);
})->with(['liquid soap family' => true, 'bar soap family' => false]);

it('previews the stages, neutralizer and final batch of a liquid soap formula', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $citricAcid = liquidSoapPreviewCitricAcid();

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => ['neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 0, 'note' => null]]],
    ]), null, $family)['liquid_process'];

    expect($process['status'])->toBe('ok')
        ->and($process['anhydrous_soap_weight'])->toBe('1190.0000')
        ->and($process['stages']['first_dilution']['mass'])->toBe('3400.0000')
        ->and($process['neutralizer']['type'])->toBe('citric_acid_anhydrous')
        ->and($process['neutralizer']['recommended_weight'])->toBe('6.5062')
        ->and($process['stages']['neutralization']['add_weight'])->toBe('32.5310')
        ->and($process['target_final_weight'])->toBe('4760.0000')
        ->and($process['final_weight'])->toBe('4760.0000');
});

it('turns additions entered as a share of the final batch into weights', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $clay = Ingredient::factory()->create(['category' => IngredientCategory::MineralsSaltsPowders, 'is_active' => true]);

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => [
            'fragrance' => [['id' => 'lavender', 'ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2]],
            'additives' => [['id' => 'clay', 'ingredient_id' => $clay->id, 'percentage' => 5, 'weight' => 50]],
        ],
    ]), null, $family)['liquid_process'];

    $additions = collect($process['additions'])->keyBy('id');

    expect($additions['lavender']['weight'])->toBe('95.2000')
        ->and($additions['lavender']['final_percentage'])->toBe('2.0000')
        ->and($additions['clay']['weight'])->toBe('50.0000')
        ->and($additions['clay']['final_percentage'])->toBe('1.0504')
        ->and($process['stages']['final_dilution']['additions_weight'])->toBe('145.2000')
        ->and($process['final_weight'])->toBe('4760.0000');
});

it('uses a weighed paste for the batch without needing it in the formula', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'liquid_process' => ['measured_paste_weight' => '1680'],
    ]), null, $family)['liquid_process'];

    expect($process['paste']['weight'])->toBe('1680.0000')
        ->and($process['paste']['evaporated_weight'])->toBe('40.6730')
        ->and($process['final_weight'])->toBe('4760.0000');
});

it('keeps the paste preview when the liquid soap settings cannot be made', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();

    $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'liquid_process' => ['first_dilution_percentage' => 30, 'final_dilution_percentage' => 35],
    ]), null, $family);

    expect($calculation['lye']['selected']['koh_weight'])->toBe(195.7)
        ->and($calculation['liquid_process']['status'])->toBe('invalid')
        ->and($calculation['liquid_process']['message'])->toContain('must not be more concentrated');
});

it('stores additions entered as a share of the final batch as weights and shares of the oils', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'liquid_process' => ['measured_paste_weight' => '1680'],
        'phase_items' => ['fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]]],
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $row = $service->versionPayload($recipe, $version->id)['phaseItems']['fragrance'][0];

    expect((float) $row['weight'])->toBe(95.2)
        ->and((float) $row['percentage'])->toBe(9.52)
        ->and(RecipeVersion::withoutGlobalScopes()->findOrFail($version->id)->calculation_context['liquid_process'])
        ->not->toHaveKey('measured_paste_weight');
});

it('rejects saving more neutralizer than the free KOH needs', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => ['neutralization' => [['ingredient_id' => liquidSoapPreviewCitricAcid()->id, 'percentage' => 1, 'weight' => 10, 'note' => null]]],
    ]);

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['neutralization'][0] ?? null)->toContain('more than the free KOH needs'));
});
