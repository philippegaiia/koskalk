<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeWorkbenchPreviewService;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

/*
 * Baseline for the existing soap bench. A dedicated liquid soap paste bench is
 * planned on shared services; these tests must keep passing unchanged so the
 * soap bench's phases, defaults, calculations and saved formulas stay as they
 * are, whatever lye a soap formula uses.
 */

uses(RefreshDatabase::class);

function soapBenchFamily(): ProductFamily
{
    return ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
}

function soapBenchOil(): Ingredient
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.188]);

    return $oil;
}

/**
 * 1000 g of one oil at 5% superfat, KOH 90%, alkali solution at 30%.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function soapBenchPayload(Ingredient $oil, array $overrides = []): array
{
    return array_merge([
        'name' => 'Baseline soap',
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'none',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'lye_concentration',
        'water_value' => 30,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);
}

it('gives soap family formulas the soap phases on an oil-weight basis', function () {
    $family = soapBenchFamily();

    $viewData = app(RecipeWorkbenchViewDataBuilder::class)->build($family, null, null);

    expect($viewData['productFamily']['calculation_basis'])->toBe('initial_oils')
        ->and(collect($viewData['phases'])->pluck('key')->all())->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance'])
        ->and(collect($viewData['phases'])->pluck('phase_type')->all())->toBe(['reaction_core', 'reaction_medium', 'post_reaction', 'post_reaction'])
        ->and($viewData['preferredMassUnit'])->toBe('kg')
        ->and($viewData['savedDraft'])->toBeNull();
});

it('previews the soap bench lye and alkali solution for each lye type', function (string $lyeType, array $expected) {
    $oil = soapBenchOil();

    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation(
        soapBenchPayload($oil, ['lye_type' => $lyeType]),
    );

    expect($calculation['lye']['selected']['naoh_weight'])->toBe($expected['naoh'])
        ->and($calculation['lye']['selected']['koh_weight'])->toBe($expected['koh'])
        ->and($calculation['lye']['selected']['koh_to_weigh'])->toBe($expected['koh_to_weigh'])
        ->and($calculation['lye']['water']['weight'])->toBe($expected['water'])
        ->and($calculation['lye']['excess']['koh_weight'])->toBe(0.0)
        ->and($calculation['lye']['superfat_percentage'])->toBe(5.0);
})->with([
    'NaOH' => ['naoh', ['naoh' => 127.3418, 'koh' => 0.0, 'koh_to_weigh' => 0.0, 'water' => 297.1309]],
    'KOH' => ['koh', ['naoh' => 0.0, 'koh' => 178.6, 'koh_to_weigh' => 198.4444, 'water' => 416.7333]],
    'dual lye at 40% KOH' => ['dual', ['naoh' => 76.4051, 'koh' => 71.44, 'koh_to_weigh' => 79.3778, 'water' => 344.9719]],
]);

it('saves and reloads a soap formula in the soap family with its lye settings unchanged', function (string $lyeType) {
    $user = User::factory()->create();
    $family = soapBenchFamily();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = soapBenchOil();
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, soapBenchPayload($oil, [
        'product_type_id' => $productType->id,
        'lye_type' => $lyeType,
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $payload = $service->versionPayload($recipe, $version->id);

    expect($recipe->product_family_id)->toBe($family->id)
        ->and($payload['lyeType'])->toBe($lyeType)
        ->and($payload['kohPurity'])->toBe(90.0)
        ->and($payload['dualKohPercentage'])->toBe(40.0)
        ->and($payload['waterMode'])->toBe('lye_concentration')
        ->and($payload['waterValue'])->toBe(30.0)
        ->and($payload['superfat'])->toBe(5.0)
        ->and($payload['manufacturingMode'])->toBe('saponify_in_formula')
        ->and(array_keys($payload['phaseItems']))->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance'])
        ->and($payload['phaseItems']['lye_water'])->toBe([])
        ->and($payload['phaseItems']['saponified_oils'][0]['ingredient_id'])->toBe($oil->id);
})->with(['naoh', 'koh', 'dual']);

it('starts a new soap formula in the browser with the bar soap defaults', function () {
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
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const workbench = createRecipeWorkbench({ productFamily: { slug: 'soap' }, numberLocaleOptions: { en_US: '1,234.56' } });

assert.equal(workbench.manufacturingMode, 'saponify_in_formula');
assert.equal(workbench.exposureMode, 'rinse_off');
assert.equal(workbench.lyeType, 'naoh');
assert.equal(workbench.kohPurity, 90);
assert.equal(workbench.dualKohPercentage, 40);
assert.equal(workbench.waterMode, 'lye_concentration');
assert.equal(workbench.waterValue, 30);
assert.equal(workbench.superfat, 5);
assert.equal(workbench.oilWeight, 1000);
assert.deepEqual(workbench.phaseOrder.map(phase => phase.key), ['saponified_oils', 'lye_water', 'additives', 'fragrance']);
JS;

    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
