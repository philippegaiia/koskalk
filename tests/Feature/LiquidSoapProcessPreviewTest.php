<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->and($additions['lavender']['requested_final_percentage'])->toBe(2.0)
        ->and($additions['clay']['requested_final_percentage'])->toBeNull()
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

it('saves the entered neutralizer dose above the theoretical recommendation without changing it', function (string $operation, float $dose) {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => ['neutralization' => [['ingredient_id' => liquidSoapPreviewCitricAcid()->id, 'percentage' => $dose / 10, 'weight' => $dose, 'note' => null]]],
    ]);

    $service = app(RecipeWorkbenchService::class);
    $version = $service->{$operation}($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $snapshot = $service->versionSnapshot($recipe, $version->id);

    expect((float) $snapshot['draft']['phaseItems']['neutralization'][0]['weight'])->toBe($dose)
        ->and((float) RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('ingredient_id', $payload['phase_items']['neutralization'][0]['ingredient_id'])->sole()->weight)->toBe($dose)
        ->and($snapshot['calculation']['liquid_process']['warnings'])->toContain('excess_acid_splits_soap')
        ->and($snapshot['calculation']['liquid_process']['status'])->toBe('acid_exceeds_free_koh');
})->with([
    'save above the estimate' => ['save', 10.0],
    'publish above the estimate' => ['publish', 10.0],
    'save well outside any small tolerance' => ['save', 100.0],
]);

it('preserves addition and dilution shares when saving a dose outside the process model', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = liquidSoapPreviewOil();
    $acid = liquidSoapPreviewCitricAcid();
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    $payload = liquidSoapPreviewPayload($oil, [
        'product_type_id' => $productType->id,
        'phase_items' => [
            'neutralization' => [['ingredient_id' => $acid->id, 'percentage' => 1, 'weight' => 10]],
            'fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 9.52, 'weight' => 95.2, 'final_percentage' => 2]],
            'first_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 25, 'weight' => 0, 'note' => 'First dilution']],
            'final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 50, 'weight' => 0, 'note' => 'Final dilution']],
        ],
    ]);
    $service = app(RecipeWorkbenchService::class);
    $version = $service->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $saved = $service->versionPayload($recipe, $version->id);

    expect($saved['phaseItems']['fragrance'][0]['final_percentage'])->toBe(2.0)
        ->and($saved['phaseItems']['fragrance'][0]['weight'])->toBe(95.2)
        ->and($saved['phaseItems']['first_dilution'][0]['percentage'])->toBe(25.0)
        ->and($saved['phaseItems']['final_dilution'][0]['percentage'])->toBe(50.0)
        ->and($saved['phaseItems']['final_dilution'][0]['note'])->toBe('Final dilution')
        ->and($saved['liquidProcess']['neutralizerSolutionPercentage'])->toBe(20.0);

    $payload['phase_items'] = $saved['phaseItems'];
    $resaved = $service->save($user, $family, $payload, $recipe);

    expect($service->versionPayload($recipe, $resaved->id)['phaseItems']['fragrance'][0]['final_percentage'])->toBe(2.0);

    $payload['phase_items']['neutralization'][0] = ['ingredient_id' => $acid->id, 'percentage' => 0.65062, 'weight' => 6.5062];
    $corrected = $service->save($user, $family, $payload, $recipe);
    $snapshot = $service->versionSnapshot($recipe, $corrected->id);

    expect($snapshot['calculation']['liquid_process']['status'])->toBe('ok')
        ->and($snapshot['draft']['phaseItems']['fragrance'][0]['weight'])->toBe(95.2)
        ->and($snapshot['draft']['phaseItems']['final_dilution'][0]['weight'])->toBeGreaterThan(0);
});

it('accepts a two milligram neutralizer excess through preview save and reload', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $service = app(RecipeWorkbenchService::class);
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => ['neutralization' => [['ingredient_id' => liquidSoapPreviewCitricAcid()->id, 'percentage' => 0.65082, 'weight' => 6.5082]]],
    ]);

    $preview = $service->previewSoapCalculation($payload, null, $family)['liquid_process'];

    expect($preview['status'])->toBe('ok')
        ->and($preview['warnings'])->not->toContain('excess_acid_splits_soap')
        ->and((float) $preview['neutralizer']['weight'])->toBe(6.5082)
        ->and((float) array_sum($preview['composition']))->toEqualWithDelta((float) $preview['final_weight'], 0.0005);

    $version = $service->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $row = $service->versionPayload($recipe, $version->id)['phaseItems']['neutralization'][0];

    expect((float) $row['weight'])->toBe(6.5082)
        ->and((float) RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('ingredient_id', $row['ingredient_id'])->sole()->weight)->toBe(6.5082);

    $row['percentage'] = 0.65082;
    $payload['phase_items']['neutralization'] = [$row];
    $resaved = $service->save($user, $family, $payload, $recipe);

    expect((float) $service->versionPayload($recipe, $resaved->id)['phaseItems']['neutralization'][0]['weight'])->toBe(6.5082);
});

it('uses the batch unit for the neutralizer rounding allowance', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'oil_unit' => 'kg',
        'oil_weight' => 1,
        'phase_items' => [
            'saponified_oils' => [['percentage' => 100, 'weight' => 1]],
            'neutralization' => [['ingredient_id' => liquidSoapPreviewCitricAcid()->id, 'percentage' => 0.66, 'weight' => 0.0066]],
        ],
    ]);

    expect(app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, null, $family)['liquid_process']['status'])->toBe('ok');

    $payload['oil_unit'] = 'g';
    $payload['oil_weight'] = 1000;
    $payload['phase_items']['saponified_oils'][0]['weight'] = 1000;
    $payload['phase_items']['neutralization'][0]['weight'] = 6.6;

    expect(app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, null, $family)['liquid_process']['status'])->toBe('acid_exceeds_free_koh');
});

function liquidSoapPreviewSaponifiableAdditive(): Ingredient
{
    $butter = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $butter->id, 'koh_sap_value' => 0.19]);

    return $butter;
}

it('keeps saponifiable additions from absorbing the free KOH of a liquid soap paste', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $citricAcid = liquidSoapPreviewCitricAcid();
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => [
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65, 'weight' => 6.5, 'note' => null]],
            'additives' => [['ingredient_id' => liquidSoapPreviewSaponifiableAdditive()->id, 'percentage' => 5, 'weight' => 50, 'note' => null]],
        ],
    ]);

    $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, null, $family);

    expect($calculation['lye']['excess']['koh_weight'])->toBe(5.7)
        ->and($calculation['lye']['post_reaction_oils_weight'])->toBe(0.0)
        ->and($calculation['liquid_process']['status'])->toBe('ok')
        ->and($calculation['liquid_process']['neutralizer']['excess_acid_weight'])->toBe('0.0000');
});

it('saves the same neutralizer dose the preview accepts beside saponifiable additions', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => [
            'neutralization' => [['ingredient_id' => liquidSoapPreviewCitricAcid()->id, 'percentage' => 0.65, 'weight' => 6.5, 'note' => null]],
            'additives' => [['ingredient_id' => liquidSoapPreviewSaponifiableAdditive()->id, 'percentage' => 5, 'weight' => 50, 'note' => null]],
        ],
    ]);

    $version = app(RecipeWorkbenchService::class)->save($user, $family, $payload);

    expect(RecipeVersion::withoutGlobalScopes()->findOrFail($version->id)->calculation_context['liquid_process']['final_dilution_percentage'])->toBe('25');
});

it('keeps the weights of other additions when one is entered as a share of the final batch', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $clay = Ingredient::factory()->create(['category' => IngredientCategory::MineralsSaltsPowders, 'is_active' => true]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => [
            'additives' => [['ingredient_id' => $clay->id, 'percentage' => 5, 'weight' => 0, 'note' => null]],
            'fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
        ],
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $phaseItems = $service->versionPayload($recipe, $version->id)['phaseItems'];

    expect((float) $phaseItems['additives'][0]['weight'])->toBe(50.0)
        ->and((float) $phaseItems['additives'][0]['percentage'])->toBe(5.0)
        ->and((float) $phaseItems['fragrance'][0]['weight'])->toBe(95.2);
});

it('reports each addition as a share of the batch actually reached', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $clay = Ingredient::factory()->create(['category' => IngredientCategory::MineralsSaltsPowders, 'is_active' => true]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => [
            'additives' => [['id' => 'clay', 'ingredient_id' => $clay->id, 'percentage' => 140, 'weight' => 1400]],
            'fragrance' => [['id' => 'lavender', 'ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2]],
        ],
    ]), null, $family)['liquid_process'];

    $additions = collect($process['additions'])->keyBy('id');

    expect($process['stages']['final_dilution']['status'])->toBe('target_exceeded')
        ->and($process['target_final_weight'])->toBe('4760.0000')
        ->and($process['final_weight'])->toBe('4895.2000')
        ->and($additions['clay']['final_percentage'])->toBe('28.5994')
        ->and($additions['clay']['requested_final_percentage'])->toBeNull()
        ->and($additions['lavender']['weight'])->toBe('95.2000')
        ->and($additions['lavender']['final_percentage'])->toBe('1.9448')
        ->and($additions['lavender']['requested_final_percentage'])->toBe(2.0);
});

it('returns the liquid soap process in the snapshots of saved and published versions', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = liquidSoapPreviewOil();
    $service = app(RecipeWorkbenchService::class);

    $draft = $service->save($user, $family, liquidSoapPreviewPayload($oil, ['product_type_id' => $productType->id]));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->saveAsNewVersion($user, $family, liquidSoapPreviewPayload($oil, ['product_type_id' => $productType->id]), $recipe);
    $published = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->latest('version_number')->firstOrFail();

    $currentSnapshot = $service->currentVersionSnapshot($recipe->fresh());
    $publishedSnapshot = $service->versionSnapshot($recipe->fresh(), $published->id);

    expect($currentSnapshot['calculation']['liquid_process']['status'])->toBe('ok')
        ->and($currentSnapshot['calculation']['liquid_process']['final_weight'])->toBe('4760.0000')
        ->and($publishedSnapshot['calculation']['liquid_process']['final_weight'])->toBe('4760.0000');
});

it('keeps bar soap snapshots free of liquid soap results', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'superfat' => 5,
    ]));

    $snapshot = $service->currentVersionSnapshot(Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id));

    expect($snapshot['calculation'])->not->toHaveKey('liquid_process');
});

it('previews each dilution liquid as part of its step water', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $citricAcid = liquidSoapPreviewCitricAcid();
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => [
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 0, 'note' => null]],
            'final_dilution' => [['id' => 'h1', 'ingredient_id' => $hydrosol->id, 'percentage' => 30]],
        ],
    ]), null, $family)['liquid_process'];

    $finalAdd = (float) $process['stages']['final_dilution']['add_weight'];
    $liquid = $process['dilution_liquids']['final_dilution']['liquids'][0];

    expect($liquid['ingredient_id'])->toBe($hydrosol->id)
        ->and($liquid['weight'])->toEqualWithDelta($finalAdd * 0.3, 0.0001)
        ->and($process['dilution_liquids']['final_dilution']['water_weight'] + $liquid['weight'])->toEqualWithDelta($finalAdd, 0.0001)
        ->and((float) $process['composition']['dilution_liquids_weight'])->toEqualWithDelta($liquid['weight'], 0.0001)
        ->and((float) $process['final_weight'])->toEqualWithDelta(4760.0, 0.0001);
});

it('warns when a step with liquids adds nothing', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);

    $process = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'liquid_process' => ['first_dilution_percentage' => 80],
        'phase_items' => ['first_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 50]]],
    ]), null, $family)['liquid_process'];

    expect($process['warnings'])->toContain('dilution_liquid_unused_first_dilution');
});

it('reports dilution liquids above all of a step water without losing the paste', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    $milk = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);

    $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation(liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'phase_items' => ['final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 60], ['ingredient_id' => $milk->id, 'percentage' => 50]]],
    ]), null, $family);

    expect($calculation['liquid_process']['status'])->toBe('invalid')
        ->and($calculation['lye']['excess']['koh_weight'])->toBe(5.7);
});

it('keeps an addition at its share of the finished batch when the final dilution changes', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $clay = Ingredient::factory()->create(['category' => IngredientCategory::MineralsSaltsPowders, 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);
    $oil = liquidSoapPreviewOil();

    $version = $service->save($user, $family, liquidSoapPreviewPayload($oil, [
        'product_type_id' => $productType->id,
        'phase_items' => [
            'additives' => [['ingredient_id' => $clay->id, 'percentage' => 5, 'weight' => 50, 'note' => null]],
            'fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
        ],
    ]));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $saved = $service->versionPayload($recipe, $version->id)['phaseItems'];

    // A weight sent without a share is kept and its share of the batch saved.
    expect($saved['fragrance'][0]['final_percentage'])->toBe(2.0)
        ->and($saved['additives'][0]['final_percentage'])->toBe(round(50 * 100 / 4760, 4));

    $resaved = $service->save($user, $family, liquidSoapPreviewPayload($oil, [
        'product_type_id' => $productType->id,
        'liquid_process' => ['final_dilution_percentage' => 20],
        'phase_items' => ['additives' => $saved['additives'], 'fragrance' => $saved['fragrance']],
    ]), $recipe);
    $rows = $service->versionPayload($recipe, $resaved->id)['phaseItems'];

    expect($rows['fragrance'][0]['final_percentage'])->toBe(2.0)
        ->and((float) $rows['fragrance'][0]['weight'])->toEqualWithDelta(5950 * 0.02, 0.001)
        ->and($rows['additives'][0]['final_percentage'])->toBe(round(50 * 100 / 4760, 4));
});

it('leaves bar soap rows without a share of the finished batch', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'superfat' => 5,
        'phase_items' => ['fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 2, 'weight' => 20, 'note' => null]]],
    ]));

    expect($service->versionPayload(Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id), $version->id)['phaseItems']['fragrance'][0])
        ->not->toHaveKey('final_percentage');
});

it('recovers the share of additions saved before shares were stored', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $barFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $barType = ProductType::factory()->create(['product_family_id' => $barFamily->id]);
    $service = app(RecipeWorkbenchService::class);
    $liquid = $service->save($user, $family, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => ['fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]]],
    ]));
    $bar = $service->save($user, $barFamily, liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $barType->id,
        'superfat' => 5,
        'phase_items' => ['fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 2, 'weight' => 20, 'note' => null]]],
    ]));
    RecipeItem::withoutGlobalScopes()->update(['final_percentage' => null]);
    $liquidRow = fn () => RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $liquid->id)->where('ingredient_id', $fragrance->id)->sole();

    $this->artisan('liquid-soap:backfill-addition-shares')->expectsOutputToContain('Would set: 1')->assertSuccessful();
    expect($liquidRow()->final_percentage)->toBeNull();

    $this->artisan('liquid-soap:backfill-addition-shares --apply')->expectsOutputToContain('Set: 1')->assertSuccessful();

    expect($liquidRow()->final_percentage)->toBe('2.0000')
        ->and(RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $bar->id)->whereNotNull('final_percentage')->count())->toBe(0);
    $this->artisan('liquid-soap:backfill-addition-shares --apply')->expectsOutputToContain('Set: 0')->assertSuccessful();
});

it('recovers a weightless addition from its share of the oils and reports a version it cannot calculate', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $fragrance = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);
    $payload = liquidSoapPreviewPayload(liquidSoapPreviewOil(), [
        'product_type_id' => $productType->id,
        'phase_items' => ['fragrance' => [['ingredient_id' => $fragrance->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]]],
    ]);
    $weightless = $service->save($user, $family, $payload);
    $broken = $service->save($user, $family, $payload);
    RecipeItem::withoutGlobalScopes()->update(['final_percentage' => null]);
    RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $weightless->id)->update(['weight' => 0]);
    $context = $broken->calculation_context;
    unset($context['liquid_process']);
    $broken->forceFill(['calculation_context' => $context])->save();

    $this->artisan('liquid-soap:backfill-addition-shares --apply')
        ->expectsOutputToContain('Set: 1; unresolved versions (unchanged): 1')
        ->assertFailed();

    $fragranceShare = fn (RecipeVersion $version): ?string => RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('ingredient_id', $fragrance->id)->value('final_percentage');

    expect($fragranceShare($weightless))->toBe('2.0000')
        ->and($fragranceShare($broken))->toBeNull();
});
