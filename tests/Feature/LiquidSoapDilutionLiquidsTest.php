<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchPhaseBlueprints;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('gives liquid soap first and final dilution phases in process order and bar soap none', function () {
    $blueprints = app(RecipeWorkbenchPhaseBlueprints::class);

    expect(collect($blueprints->all(ProductFamily::factory()->liquidSoapPaste()->make()))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'first_dilution', 'neutralization', 'additives', 'fragrance', 'final_dilution'])
        ->and(collect($blueprints->all(ProductFamily::factory()->make(['slug' => 'soap'])))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance'])
        ->and($blueprints->find('final_dilution')['phase_type'])->toBe('post_reaction');
});

/** @return array{user: User, family: ProductFamily, payload: array<string, mixed>, hydrosol: Ingredient} */
function dilutionLiquidsFormula(): array
{
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);
    $citricAcid = Ingredient::factory()->create(['category' => IngredientCategory::PhAdjustersBuffers, 'owner_type' => null, 'owner_id' => null, 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);

    return ['user' => $user, 'family' => $family, 'hydrosol' => $hydrosol, 'payload' => [
        'name' => 'Liquid castile', 'product_type_id' => $productType->id, 'oil_unit' => 'g', 'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula', 'exposure_mode' => 'rinse_off', 'regulatory_regime' => 'none', 'editing_mode' => 'percentage',
        'lye_type' => 'koh', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40, 'water_mode' => 'lye_concentration', 'water_value' => 28, 'superfat' => -3,
        'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 6.5062, 'note' => null]],
            'additives' => [], 'fragrance' => [],
            'final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => 'Add cool']],
        ],
    ]];
}

it('saves a dilution liquid as a % of its step water with its process weight', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload, 'hydrosol' => $hydrosol] = dilutionLiquidsFormula();

    $version = app(RecipeWorkbenchService::class)->save($user, $family, $payload);
    $phase = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('slug', 'final_dilution')->sole();
    $item = RecipeItem::withoutGlobalScopes()->where('recipe_phase_id', $phase->id)->sole();

    expect($item->ingredient_id)->toBe($hydrosol->id)
        ->and($item->percentage)->toBe('30.0000')
        ->and((float) $item->weight)->toEqualWithDelta(398.24, 0.01)
        ->and($item->note)->toBe('Add cool')
        ->and((float) RecipeVersion::withoutGlobalScopes()->find($version->id)->calculation_context['totals']['oil_percentage'])->toBe(100.0);
});

it('refuses dilution liquids that replace more than all of a step water', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload] = dilutionLiquidsFormula();
    $other = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    $payload['phase_items']['final_dilution'][] = ['ingredient_id' => $other->id, 'percentage' => 80, 'weight' => 0, 'note' => null];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(ValidationException::class);
});

it('refuses an alkali as a dilution liquid', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload] = dilutionLiquidsFormula();
    $koh = Ingredient::factory()->create(['category' => IngredientCategory::SoapmakingAlkalis, 'is_active' => true]);
    $payload['phase_items']['first_dilution'] = [['ingredient_id' => $koh->id, 'percentage' => 10, 'weight' => 0, 'note' => null]];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(ValidationException::class, __('workbench.validation.lye_liquid_alkali'));
});
