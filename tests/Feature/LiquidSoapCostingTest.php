<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RecipeVersionCostingSynchronizer;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeWorkbenchOutputBuilder;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('prices the dilution and neutralizer solution water of a saved liquid soap with the plain water', function () {
    $user = User::factory()->create();
    Ingredient::factory()->create([
        'catalog_key' => 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::PotassiumHydroxide,
        'display_name' => 'Potassium hydroxide',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $water = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'subcategory' => IngredientSubcategory::Water,
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);
    $citricAcid = Ingredient::factory()->create([
        'category' => IngredientCategory::PhAdjustersBuffers,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous,
    ]);
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $version = app(RecipeWorkbenchService::class)->save($user, $family, [
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
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 6.5062, 'note' => null]],
            'additives' => [],
            'fragrance' => [],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($version, $user);

    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromCosting(
        recipe: $recipe,
        version: $version->fresh(),
        costing: $costing->fresh(['items.ingredient', 'packagingItems.packagingItem']),
        batchBasisValue: '1000',
        unitsProduced: 1,
        includeDerivedRows: true,
        requireCompletePackagingTotal: true,
        batchBasisUnit: 'g',
    );
    $rows = collect($preview['ingredient_rows']);
    $waterRow = $rows->firstWhere('phase_key', 'implicit_water');
    $neutralizerRow = $rows->firstWhere('phase_key', 'neutralization');

    expect($waterRow['ingredient_id'])->toBe($water->id)
        ->and($waterRow['quantity'])->toEqualWithDelta(503.2286 + 1679.327 + 1327.469 + 26.0248, 0.001)
        ->and($neutralizerRow['ingredient_id'])->toBe($citricAcid->id)
        ->and($neutralizerRow['quantity'])->toEqualWithDelta(6.506, 0.001);
});

it('adds the liquid soap dilution water to the workbench plain-water costing row only on liquid formulas', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { pathToFileURL } from 'node:url';

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, nextResolve) {
            if (specifier.startsWith('.') && !specifier.endsWith('.js')) {
                try {
                    return await nextResolve(specifier, context);
                } catch {
                    return nextResolve(specifier + '.js', context);
                }
            }

            return nextResolve(specifier, context);
        }
    `),
    pathToFileURL(`${process.cwd()}/`).href,
);

global.window = { location: { hash: '' }, localStorage: { getItem: () => null, setItem: () => {} } };
global.document = { addEventListener() {}, removeEventListener() {} };
Object.defineProperty(global, 'navigator', { value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 }, configurable: true });

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const preview = { liquid_process: {
    status: 'ok',
    stages: { first_dilution: { add_weight: '1679.3270' }, final_dilution: { add_weight: '1327.4690' } },
    neutralizer: { weight: '6.5062', solution_weight: '32.5310' },
} };
const waterRow = (productFamily) => {
    const workbench = createRecipeWorkbench({ productFamily, numberLocaleOptions: { en_US: '1,234.56' } });
    workbench.oilUnit = 'g';
    workbench.oilWeight = 1000;
    workbench.costingWaterIngredient = { ingredient_id: 3, name: 'Water' };
    workbench.backendCalculation = preview;

    return { row: workbench.costingImplicitWaterRows()[0], lyeWater: workbench.lyeLiquidWaterWeight(), dilution: workbench.liquidDilutionWaterWeight() };
};

const liquid = waterRow({ slug: 'liquid-soap', soap_process: 'liquid_paste' });
assert.equal(Math.round(liquid.dilution * 10000) / 10000, 3032.8208);
assert.equal(Math.round((liquid.row.weight - liquid.lyeWater) * 10000) / 10000, 3032.8208);

const bar = waterRow({ slug: 'soap', soap_process: 'bar' });
assert.equal(bar.dilution, 0);
assert.equal(bar.row?.weight ?? 0, bar.lyeWater);
JS;

    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

/**
 * @return array{user: User, family: ProductFamily, productType: ProductType, oil: Ingredient, koh: Ingredient, water: Ingredient}
 */
function liquidSoapCostingContext(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $koh = Ingredient::factory()->create([
        'catalog_key' => 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::PotassiumHydroxide,
        'display_name' => 'Potassium hydroxide',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $water = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'subcategory' => IngredientSubcategory::Water,
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);
    $family = ProductFamily::factory()->liquidSoapPaste()->create();

    return [
        'user' => $user,
        'family' => $family,
        'productType' => ProductType::factory()->create(['product_family_id' => $family->id]),
        'oil' => $oil,
        'koh' => $koh,
        'water' => $water,
    ];
}

it('keeps a water price row for a liquid soap whose alkali solution uses only other liquids', function () {
    ['user' => $user, 'family' => $family, 'productType' => $productType, 'oil' => $oil, 'water' => $water] = liquidSoapCostingContext();
    $hydrosol = Ingredient::factory()->create(['display_name' => 'Lavender Hydrosol', 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);
    $version = app(RecipeWorkbenchService::class)->save($user, $family, [
        'name' => 'Hydrosol liquid soap',
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
        'superfat' => 0,
        'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'lye_water' => [['ingredient_id' => $hydrosol->id, 'percentage' => 100, 'weight' => 488.5714, 'note' => null]],
            'neutralization' => [],
            'additives' => [],
            'fragrance' => [],
        ],
    ]);

    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($version, $user);

    expect($costing->items()->where('phase_key', 'implicit_water')->value('ingredient_id'))->toBe($water->id);
});

it('exports the dilution water of a liquid soap in its current costing', function () {
    ['user' => $user, 'family' => $family, 'oil' => $oil, 'koh' => $koh, 'water' => $water] = liquidSoapCostingContext();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();

    $data = app(RecipeWorkbenchOutputBuilder::class)->build($user, [
        'workspace' => $workspace->public_id,
        'product_family' => $family->slug,
        'kind' => 'costing',
        'format' => 'xlsx',
        'draft' => [
            'name' => 'Liquid castile',
            'oil_unit' => 'g',
            'oil_weight' => 1000,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'none',
            'product_type_id' => null,
            'editing_mode' => 'percentage',
            'lye_type' => 'koh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'water_mode' => 'lye_concentration',
            'water_value' => 28,
            'superfat' => 0,
            'ifra_category_selection_mode' => 'automatic',
            'ifra_product_category_id' => null,
            'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
            'phases' => [['key' => 'saponified_oils', 'name' => 'Saponified Oils']],
            'phase_items' => [
                'saponified_oils' => [['id' => 'oil', 'ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
                'neutralization' => [],
                'additives' => [],
                'fragrance' => [],
                'lye_water' => [],
            ],
            'packaging_items' => [],
            'production_output_type' => 'finished_product',
        ],
        'manufacturing_instructions' => '',
        'costing' => [
            'loaded' => true,
            'oil_weight_for_costing' => null,
            'oil_unit_for_costing' => 'g',
            'units_produced' => null,
            'currency' => 'EUR',
            'items' => [
                ['ingredient_id' => $oil->id, 'phase_key' => 'saponified_oils', 'position' => 1, 'price_per_kg' => 10],
                ['ingredient_id' => $koh->id, 'phase_key' => 'lye_alkali', 'position' => 1, 'price_per_kg' => 20],
                ['ingredient_id' => $water->id, 'phase_key' => 'implicit_water', 'position' => 1, 'price_per_kg' => 1],
            ],
            'packaging_items' => [],
        ],
        'print_context' => 'costing',
    ]);

    $waterRow = collect($data['costingProjection']['ingredient_rows'])->firstWhere('phase_key', 'implicit_water');

    expect($data['snapshot']['calculation']['liquid_process']['final_weight'])->toBe('4760.0000')
        ->and((float) $waterRow['quantity'])->toEqualWithDelta(488.5714 + 1700.3175 + 1360.0, 0.001);
});
