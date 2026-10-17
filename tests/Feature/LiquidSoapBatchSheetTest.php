<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\FormulaDocumentBuilder;
use App\Services\RecipeCompositionExportDataBuilder;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Saved reference liquid soap: 1000 g oil at -3% superfat, citric acid as a
 * 20% solution, 2% lavender of a 4760 g batch.
 *
 * @return array<string, mixed>
 */
function liquidSoapBatchSheetDocument(array $overrides = []): array
{
    return liquidSoapBatchSheet($overrides)['document'];
}

/**
 * @return array{snapshot: array<string, mixed>, document: array<string, mixed>}
 */
function liquidSoapBatchSheet(array $overrides = []): array
{
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'display_name' => 'Olive Oil', 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);
    $citricAcid = Ingredient::factory()->create(['category' => IngredientCategory::PhAdjustersBuffers, 'display_name' => 'Citric Acid', 'owner_type' => null, 'owner_id' => null, 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $lavender = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'display_name' => 'Lavender Oil', 'is_active' => true]);
    $service = app(RecipeWorkbenchService::class);
    $version = $service->save($user, $family, array_replace_recursive([
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
            'fragrance' => [['ingredient_id' => $lavender->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
        ],
    ], $overrides));
    $snapshot = $service->currentVersionSnapshot(Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id));

    return ['snapshot' => $snapshot, 'document' => app(FormulaDocumentBuilder::class)->build($snapshot, ['calculation_basis' => 'initial_oils'])];
}

it('lays out the liquid soap batch sheet in process order with what to add at each step', function () {
    $document = liquidSoapBatchSheetDocument();
    $steps = collect($document['liquid_process']['steps'])->keyBy('key');

    expect($steps->keys()->all())->toBe(['paste', 'first_dilution', 'neutralization', 'final_dilution'])
        ->and($steps['paste']['add'])->toBeNull()
        ->and($steps['paste']['mass'])->toBe(1720.673)
        ->and($steps['first_dilution']['add'])->toBe(1679.327)
        ->and($steps['first_dilution']['mass'])->toBe(3400.0)
        ->and($steps['neutralization']['add'])->toBe(32.531)
        ->and($steps['neutralization']['instruction'])->toBe('Add Citric Acid as a 20% solution. Theoretical amount: add part of it, mix, test, then adjust.')
        ->and($steps['final_dilution']['mass'])->toBe(4760.0)
        ->and($steps['final_dilution']['anhydrous_percentage'])->toBe(25.0)
        ->and($document['liquid_process']['neutralizer'])->toMatchArray(['name' => 'Citric Acid', 'weight' => 6.5062, 'solution_weight' => 32.531])
        ->and($document['liquid_process']['additions'])->toBe([['name' => 'Lavender Oil', 'weight' => 95.2, 'final_percentage' => 2.0]]);
});

it('reports the final batch instead of a wet and cured bar on a liquid soap sheet', function () {
    $document = liquidSoapBatchSheetDocument();

    expect(collect($document['results'])->pluck('value', 'label')->all())->toBe([
        'Final batch' => 4760.0,
        'Anhydrous soap weight' => 1190.0,
        'Anhydrous soap' => 25.0,
        'Produced glycerine' => 103.9577,
        'Formula additions' => 95.2,
    ])
        ->and($document['soap_output'])->toBeNull();
});

it('lists the liquid soap tendencies with a level and no bar soap ranges', function () {
    $document = liquidSoapBatchSheetDocument();
    $qualities = $document['soap_analysis']['qualities'];

    expect($document['soap_analysis']['quality_display'])->toBe('levels')
        ->and(collect($qualities)->pluck('key')->all())->toBe(['dilution_ease', 'bubble_volume', 'creamy_lather', 'lather_stability'])
        ->and(collect($qualities)->every(fn (array $row): bool => ! array_key_exists('range', $row) && ! array_key_exists('status', $row)))->toBeTrue()
        ->and(collect($qualities)->pluck('level', 'label')->all())->toBe([
            'Dilution ease' => 'Low',
            'Bubble volume' => 'Very low',
            'Creamy lather' => 'Very low',
            'Lather stability' => 'Very low',
        ]);

    $this->blade('<x-formula-document.soap-analysis :analysis="$analysis" number-locale="en" />', ['analysis' => $document['soap_analysis']])
        ->assertSeeInOrder(['Tendencies from the fatty-acid profile', 'Level', 'Dilution ease', 'Low', 'Lather stability', 'Very low'])
        ->assertDontSee('Suggested range')
        ->assertDontSee('Unmolding firmness');
});

it('leaves out a liquid soap tendency the calculation marks not applicable', function () {
    $snapshot = [
        'draft' => ['oilWeight' => 1000, 'oilUnit' => 'g', 'phaseItems' => []],
        'calculation' => [
            'liquid_process' => ['status' => 'invalid', 'message' => 'Missing neutralizer'],
            'properties' => [
                'qualities' => ['dilution_ease' => 85, 'bubble_volume' => 20, 'creamy_lather' => 39.9, 'lather_stability' => 60, 'cured_hardness' => 30],
                'quality_applicability' => ['bubble_volume' => ['applies' => false]],
            ],
        ],
    ];

    $document = app(FormulaDocumentBuilder::class)->build($snapshot, ['calculation_basis' => 'initial_oils']);

    expect(collect($document['soap_analysis']['qualities'])->pluck('level', 'key')->all())->toBe([
        'dilution_ease' => 'Very high',
        'creamy_lather' => 'Low',
        'lather_stability' => 'High',
    ]);
});

it('prints the liquid soap steps in process order', function () {
    $document = liquidSoapBatchSheetDocument();

    $this->blade('<x-formula-document.liquid-process :document="$document" />', ['document' => $document])
        ->assertSeeInOrder(['Liquid soap process', 'Paste', 'Anhydrous soap: 1,190.00 g', 'First dilution', 'Neutralization', 'Acid: 6.51 g (theoretical 6.51 g)', 'Final dilution', 'Lavender Oil', '95.20 g · 2.00%']);
});

it('reports the soap a fatty acid neutralizer adds to the anhydrous soap weight', function () {
    $stearicAcid = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'display_name' => 'Stearic Acid', 'owner_type' => null, 'owner_id' => null, 'is_soap_saponification_trusted' => true, 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::FattyAcid]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $stearicAcid->id, 'koh_sap_value' => 0.1972]);

    $document = liquidSoapBatchSheetDocument([
        'phase_items' => ['neutralization' => [['ingredient_id' => $stearicAcid->id, 'percentage' => 2.89047, 'weight' => 28.9047, 'note' => null]]],
    ]);
    $results = collect($document['results'])->pluck('value', 'label');

    expect($results->only(['Anhydrous soap from the paste', 'Anhydrous soap weight'])->all())->toBe([
        'Anhydrous soap from the paste' => 1190.0,
        // The acid and the free KOH it takes up, less the water they release.
        'Anhydrous soap weight' => 1222.7745,
    ]);

    $this->blade('<x-formula-document.liquid-process :document="$document" />', ['document' => $document])
        ->assertSeeInOrder(['Paste', 'Anhydrous soap: 1,190.00 g', 'Neutralization', 'Anhydrous soap after neutralization: 1,222.77 g', 'Final dilution']);
});

it('prints nothing for formulas without a liquid soap process', function () {
    $this->blade('<x-formula-document.liquid-process :document="$document" />', ['document' => ['unit' => 'g', 'liquid_process' => null]])
        ->assertDontSee('Liquid soap process');
});

it('sends the weighed paste with a printed or exported batch', function () {
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
global.document = { addEventListener() {}, removeEventListener() {}, querySelector: () => null };
Object.defineProperty(global, 'navigator', { value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 }, configurable: true });

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const { captureRecipeWorkbenchOutput } = await import('./resources/js/recipe-workbench/outputs.js');
const workbench = createRecipeWorkbench({ productFamily: { slug: 'liquid-soap', soap_process: 'liquid_paste' }, numberLocaleOptions: { en_US: '1,234.56' } });
workbench.liquidProcess.measuredPasteWeight = '1680';

const payload = await captureRecipeWorkbenchOutput(workbench, { workspace: 'w', productFamily: 'liquid-soap' });

assert.equal(payload.draft.liquid_process.measured_paste_weight, '1680');
assert.equal(payload.draft.liquid_process.final_dilution_percentage, 25);
JS;

    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('lists the water and dilution liquids a step adds', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    $document = liquidSoapBatchSheetDocument(['phase_items' => ['final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]]]);
    $steps = collect($document['liquid_process']['steps'])->keyBy('key');

    expect($steps['final_dilution']['instruction'])->toStartWith('Add the additions, then ')
        ->toContain(' g water + ')
        ->toContain(' g Lavender hydrosol up to 25.0% anhydrous soap.')
        ->and($steps['first_dilution']['instruction'])->toBe('Add water to bring the paste to 35.0% anhydrous soap.');
});

it('exports dilution liquids, the neutralizer and all added water as raw materials', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    ['snapshot' => $snapshot, 'document' => $document] = liquidSoapBatchSheet(['phase_items' => ['final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]]]);
    $finalAdd = (float) data_get($snapshot, 'calculation.liquid_process.stages.final_dilution.add_weight');

    $rows = collect(app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document)['incorporated']['raw_material_rows']);

    expect($rows->firstWhere('ingredient_id', $hydrosol->id)['quantity'])->toEqualWithDelta($finalAdd * 0.3, 0.001)
        ->and($rows->firstWhere('name', 'Citric Acid')['quantity'])->toEqualWithDelta(6.506, 0.001)
        ->and($rows->sum('quantity'))->toEqualWithDelta(4760.0, 0.01);
});
