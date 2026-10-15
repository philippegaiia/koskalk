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
use App\Services\RecipeVersionCostingSynchronizer;
use App\Services\RecipeVersionCostPreviewBuilder;
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
