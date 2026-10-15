<?php

use App\Enums\IngredientCategory;
use App\Filament\Resources\ProductFamilies\Pages\EditProductFamily;
use App\Filament\Resources\ProductFamilies\ProductFamilyResource;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeWorkbenchService;
use App\SoapSap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function curedWaterSoapOil(): Ingredient
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    return $oil;
}

/**
 * @return array<string, mixed>
 */
function curedWaterSoapPayload(Ingredient $oil, array $overrides = []): array
{
    return array_merge([
        'name' => 'Cured Water Soap',
        'product_type_id' => testProductTypeIdForFamily('soap'),
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'none',
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
                ['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);
}

it('lets administrators set the soap residual water but not create families', function () {
    $soap = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditProductFamily::class, ['record' => $soap->id])
        ->fillForm(['cured_water_percentage' => 10.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($soap->refresh()->curedWaterPercentage())->toBe(10.5)
        ->and(ProductFamilyResource::canCreate())->toBeFalse();

    Livewire::test(EditProductFamily::class, ['record' => $soap->id])
        ->fillForm(['cured_water_percentage' => 45])
        ->call('save')
        ->assertHasFormErrors(['cured_water_percentage' => 'max']);
});

it('stores the family residual water on new formulas and keeps it after the default changes', function () {
    $soap = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap', 'cured_water_percentage' => 10]);
    $user = User::factory()->create();
    $oil = curedWaterSoapOil();
    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $result = $component->save(
        curedWaterSoapPayload($oil),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeTrue($result['message'] ?? '');

    $recipe = Recipe::withoutGlobalScopes()->where('name', 'Cured Water Soap')->firstOrFail();
    $version = $recipe->currentVersion()->withoutGlobalScopes()->firstOrFail();

    expect($version->calculation_context['cured_water_percentage'])->toBe(10)
        ->and($result['snapshot']['draft']['curedWaterPercentage'])->toBe(10.0)
        ->and($result['snapshot']['labeling']['basis']['residual_water_percentage'])->toBe(10.0);

    $soap->update(['cured_water_percentage' => 12]);

    $snapshot = app(RecipeWorkbenchService::class)->currentVersionSnapshot($recipe->refresh());

    expect($snapshot['draft']['curedWaterPercentage'])->toBe(10.0)
        ->and($snapshot['labeling']['basis']['residual_water_percentage'])->toBe(10.0);
});

it('keeps the historical 11 percent basis for versions saved before the setting existed', function () {
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap', 'cured_water_percentage' => 9]);
    $user = User::factory()->create();
    $oil = curedWaterSoapOil();
    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $component->save(
        curedWaterSoapPayload($oil),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    $recipe = Recipe::withoutGlobalScopes()->where('name', 'Cured Water Soap')->firstOrFail();
    $version = $recipe->currentVersion()->withoutGlobalScopes()->firstOrFail();
    $context = $version->calculation_context;
    unset($context['cured_water_percentage']);
    $version->forceFill(['calculation_context' => $context])->saveQuietly();

    $snapshot = app(RecipeWorkbenchService::class)->currentVersionSnapshot($recipe->refresh());

    expect($snapshot['draft']['curedWaterPercentage'])->toBe(SoapSap::DEFAULT_CURED_WATER_PERCENTAGE)
        ->and($snapshot['labeling']['basis']['residual_water_percentage'])->toBe(SoapSap::DEFAULT_CURED_WATER_PERCENTAGE);
});

it('derives the cured weight from the submitted residual water', function () {
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $oil = curedWaterSoapOil();

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $standard = $component->previewCalculation(curedWaterSoapPayload($oil, ['cured_water_percentage' => 11]), app(RecipeWorkbenchService::class));
    $drier = $component->previewCalculation(curedWaterSoapPayload($oil, ['cured_water_percentage' => 10]), app(RecipeWorkbenchService::class));

    $standardBasis = $standard['labeling']['basis'];
    $drierBasis = $drier['labeling']['basis'];
    $nonWaterWeight = $standardBasis['formula_weight'] - $standard['calculation']['lye']['water']['weight'];

    expect($drierBasis['residual_water_percentage'])->toBe(10.0)
        ->and($drierBasis['cured_weight'])->toBe(round($nonWaterWeight / 0.9, 5))
        ->and($standardBasis['cured_weight'])->toBe(round($nonWaterWeight / 0.89, 5))
        ->and($drierBasis['residual_water_weight'])->toBe(round($drierBasis['cured_weight'] * 0.1, 5));
});

it('falls back to the historical residual water for missing or out-of-range values', function () {
    expect(SoapSap::curedWaterPercentage(null))->toBe(11.0)
        ->and(SoapSap::curedWaterPercentage('abc'))->toBe(11.0)
        ->and(SoapSap::curedWaterPercentage(-1))->toBe(11.0)
        ->and(SoapSap::curedWaterPercentage(31))->toBe(11.0)
        ->and(SoapSap::curedWaterPercentage('10.5'))->toBe(10.5)
        ->and(SoapSap::curedWaterPercentage(0))->toBe(0.0);
});
