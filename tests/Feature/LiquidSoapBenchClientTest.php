<?php

use Symfony\Component\Process\Process;

function runLiquidSoapBenchScript(string $assertions): Process
{
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
const { serializeDraft } = await import('./resources/js/recipe-workbench/payload.js');
const { draftStateFromDraft } = await import('./resources/js/recipe-workbench/snapshot.js');
const liquidFamily = { slug: 'liquid-soap', soap_process: 'liquid_paste' };
const barFamily = { slug: 'soap', soap_process: 'bar' };
const bench = (productFamily) => createRecipeWorkbench({ productFamily, numberLocaleOptions: { en_US: '1,234.56' } });
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$assertions),
        base_path(),
    );
    $process->run();

    return $process;
}

it('starts a new liquid soap formula with KOH, a 28% alkali solution and the process defaults', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);

assert.equal(workbench.isLiquidSoapFormula, true);
assert.equal(workbench.formulaName, 'New liquid soap');
assert.equal(workbench.lyeType, 'koh');
assert.equal(workbench.kohPurity, 90);
assert.equal(workbench.waterMode, 'lye_concentration');
assert.equal(workbench.waterValue, 28);
assert.equal(workbench.superfat, 0);
assert.deepEqual({ ...workbench.liquidProcess }, {
    firstDilutionPercentage: 35,
    finalDilutionPercentage: 25,
    neutralizerSolutionPercentage: 20,
    measuredPasteWeight: '',
});
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('sends the saved liquid settings without the weighed paste and leaves bar soap payloads alone', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.liquidProcess.finalDilutionPercentage = 22.5;
liquid.liquidProcess.measuredPasteWeight = '1680';

assert.deepEqual(serializeDraft(liquid).liquid_process, {
    first_dilution_percentage: 35,
    final_dilution_percentage: 22.5,
    neutralizer_solution_percentage: 20,
});

const bar = bench(barFamily);
assert.equal(bar.isLiquidSoapFormula, false);
assert.equal(bar.liquidProcess, null);
assert.equal(Object.hasOwn(serializeDraft(bar), 'liquid_process'), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('restores saved liquid settings and keeps the paste weight typed for this batch', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.liquidProcess.measuredPasteWeight = '1680';

const next = draftStateFromDraft({
    lyeType: 'koh',
    liquidProcess: { firstDilutionPercentage: 40, finalDilutionPercentage: 30, neutralizerSolutionPercentage: 25 },
}, workbench);

assert.deepEqual(next.liquidProcess, {
    firstDilutionPercentage: 40,
    finalDilutionPercentage: 30,
    neutralizerSolutionPercentage: 25,
    measuredPasteWeight: '1680',
});
assert.equal(draftStateFromDraft({ lyeType: 'naoh' }, bench(barFamily)).liquidProcess, null);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('replaces the single neutralizer and stores its weight as a share of the oils', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = createRecipeWorkbench({
    productFamily: liquidFamily,
    numberLocaleOptions: { en_US: '1,234.56' },
    ingredients: [
        { id: 7, name: 'Citric acid', category: 'ph_adjusters_buffers', available_phases: ['additives', 'neutralization'], liquid_soap_neutralizer: 'citric_acid_anhydrous' },
        { id: 8, name: 'Lactic acid', category: 'ph_adjusters_buffers', available_phases: ['additives', 'neutralization'], liquid_soap_neutralizer: 'lactic_acid' },
        { id: 9, name: 'Kaolin', category: 'minerals_salts_powders', available_phases: ['additives'] },
    ],
});
workbench.phaseItems.neutralization = [];
workbench.oilWeight = 1000;

assert.deepEqual(workbench.liquidNeutralizerOptions.map((option) => option.id), [7, 8]);

workbench.chooseLiquidNeutralizer('7');
workbench.setLiquidNeutralizerWeight('6.5');
assert.equal(workbench.phaseItems.neutralization.length, 1);
assert.equal(workbench.liquidNeutralizerType, 'citric_acid_anhydrous');
assert.equal(workbench.liquidNeutralizerRow.percentage, 0.65);
assert.equal(Math.round(workbench.liquidNeutralizerWeight() * 10000) / 10000, 6.5);

workbench.chooseLiquidNeutralizer('8');
assert.equal(workbench.phaseItems.neutralization.length, 1);
assert.equal(workbench.liquidNeutralizerRow.ingredient_id, 8);

workbench.backendCalculation = { liquid_process: { status: 'ok', neutralizer: { recommended_weight: '9.1514' } } };
workbench.$wire = { previewCalculation: async () => ({ ok: true, calculation: workbench.backendCalculation }) };
workbench.syncIngredientListVariantSelection = () => {};
await workbench.useRecommendedLiquidNeutralizer();
assert.equal(Math.round(workbench.liquidNeutralizerWeight() * 10000) / 10000, 9.1514);

workbench.chooseLiquidNeutralizer('');
assert.equal(workbench.phaseItems.neutralization.length, 0);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('lists the process stages from the server preview and hides them while invalid', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.backendCalculation = { liquid_process: {
    status: 'ok',
    paste: { weight: '1720.6730', anhydrous_percentage: '69.1590' },
    stages: {
        first_dilution: { add_weight: '1679.3270', mass: '3400.0000', anhydrous_percentage: '35.0000', status: 'ok' },
        neutralization: { add_weight: '32.5310', mass: '3432.5310', anhydrous_percentage: '34.6683' },
        final_dilution: { add_weight: '1327.4690', mass: '4760.0000', anhydrous_percentage: '25.0000', status: 'target_exceeded' },
    },
    composition: { soap_salt_weight: '1086.0423', free_koh_weight: '0.0000', water_weight: '3600.0000' },
    composition_percentages: { soap_salt_weight: '22.8160', free_koh_weight: '0.0000', water_weight: '75.6303' },
} };

assert.deepEqual(workbench.liquidStageRows().map((stage) => [stage.key, stage.mass, stage.status]), [
    ['paste', '1720.6730', 'ok'],
    ['first_dilution', '3400.0000', 'ok'],
    ['neutralization', '3432.5310', 'ok'],
    ['final_dilution', '4760.0000', 'target_exceeded'],
]);
assert.deepEqual(workbench.liquidCompositionRows().map((row) => row.key), ['soap_salt_weight', 'water_weight']);

workbench.backendCalculation = { liquid_process: { status: 'invalid', message: 'nope' } };
assert.equal(workbench.liquidProcessIsReady, false);
assert.deepEqual(workbench.liquidStageRows(), []);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('preserves the more precise saved neutralizer dose through reload and batch resizing', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.applyDraft({ oilWeight: 6000, phaseItems: { neutralization: [
    { id: 'acid', ingredient_id: 7, percentage: 2.3713, weight: 142.2778 },
] } });
assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 142.2778) < 1e-9);
assert.ok(Math.abs(serializeDraft(workbench).phase_items.neutralization[0].weight - 142.2778) < 1e-9);
workbench.oilWeight = 12000;
assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 284.5556) < 1e-9);

workbench.applyDraft({ oilWeight: 1, oilUnit: 'kg', phaseItems: { neutralization: [
    { id: 'acid', ingredient_id: 7, percentage: 0.7191, weight: 0.0072 },
] } });
assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 0.007191) < 1e-12);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('discards a preview that belongs to earlier formula inputs', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
let finishPreview;
workbench.$wire = { previewCalculation: () => new Promise(resolve => { finishPreview = resolve; }) };
workbench.backendCalculation = { current: true };
const preview = workbench.refreshCalculationPreview();
workbench.superfat = -10;
finishPreview({ ok: true, calculation: { stale: true } });
await preview;
assert.deepEqual(workbench.backendCalculation, { current: true });
clearTimeout(workbench.calculationPreviewTimer);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('uses a fresh recommendation without saving and saves that dose on the next explicit save', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.oilWeight = 6000;
workbench.superfat = -10;
workbench.phaseItems.neutralization = [{ id: 'acid', ingredient_id: 7, percentage: 0.7114333333, weight: 42.686 }];
workbench.backendCalculation = { liquid_process: { neutralizer: { recommended_weight: '42.6860' } } };
workbench.syncIngredientListVariantSelection = () => {};
let finishPreview;
let saved = null;
workbench.$wire = {
    previewCalculation: draft => {
        assert.equal(draft.superfat, -10);
        return new Promise(resolve => { finishPreview = resolve; });
    },
    save: async draft => { saved = draft; return { ok: true }; },
};
workbench.flushCostingSave = async () => true;
workbench.queueRevisionMutation = async action => action();
workbench.activateSavedRecipeEditing = async () => {};
const recommendation = workbench.useRecommendedLiquidNeutralizer();
assert.equal(saved, null);
const saving = workbench.save();
assert.equal(saved, null);
finishPreview({ ok: true, calculation: { liquid_process: { neutralizer: { recommended_weight: '142.2780' } } } });
await recommendation;
await saving;
assert.ok(Math.abs(saved.phase_items.neutralization[0].weight - 142.278) < 1e-9);
assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 142.278) < 1e-9);
clearTimeout(workbench.calculationPreviewTimer);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('sends the weighed paste with the preview request only', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const { refreshCalculationPreview } = await import('./resources/js/recipe-workbench/bridge.js');
const workbench = bench(liquidFamily);
let sentDraft = null;
workbench.$wire = { previewCalculation: async (draft) => { sentDraft = draft; return { ok: true, calculation: null }; } };
workbench.syncIngredientListVariantSelection = () => {};
workbench.liquidProcess.measuredPasteWeight = '1680';

await refreshCalculationPreview(workbench);

assert.equal(sentDraft.liquid_process.measured_paste_weight, '1680');
assert.equal(Object.hasOwn(serializeDraft(workbench).liquid_process, 'measured_paste_weight'), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('keeps a recommendation requested while an earlier save is finishing', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const { persistWorkbench } = await import('./resources/js/recipe-workbench/bridge.js');
const workbench = bench(liquidFamily);
workbench.recipeId = 1;
workbench.oilWeight = 6000;
workbench.phaseItems.neutralization = [{ id: 'acid', ingredient_id: 7, percentage: 0.7114333333, weight: 42.686 }];
workbench.syncIngredientListVariantSelection = () => {};
let finishSave;
let finishPreview;
workbench.$wire = {
    save: () => new Promise(resolve => { finishSave = resolve; }),
    previewCalculation: () => new Promise(resolve => { finishPreview = resolve; }),
};
const saving = persistWorkbench(workbench, 'save');
const recommendation = workbench.useRecommendedLiquidNeutralizer();
finishSave({ ok: true, snapshot: { draft: { oilWeight: 6000, phaseItems: { neutralization: [
    { id: 'saved-acid', ingredient_id: 7, percentage: 0.7114, weight: 42.686 },
] } } } });
await saving;
finishPreview({ ok: true, calculation: { liquid_process: { neutralizer: { recommended_weight: '142.2780' } } } });
await recommendation;
assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 142.278) < 1e-9);
assert.equal(workbench.hasUnsavedWorkbenchChanges(), true);
clearTimeout(workbench.calculationPreviewTimer);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('sizes additions typed as a share of the final batch and sends only typed shares', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.oilWeight = 1000;
workbench.phaseItems.additives = [{ id: 'clay', ingredient_id: 9, name: 'Clay', percentage: 5 }];
workbench.phaseItems.fragrance = [{ id: 'lavender', ingredient_id: 10, name: 'Lavender', percentage: 0 }];
workbench.backendCalculation = { liquid_process: {
    status: 'ok',
    target_final_weight: '4760.0000',
    additions: [{ id: 'clay', weight: '50.0000', final_percentage: '1.0504' }],
} };

const lavender = workbench.phaseItems.fragrance[0];
const clay = workbench.phaseItems.additives[0];
workbench.setLiquidAdditionShare(lavender, '2');

assert.equal(workbench.liquidAdditionShare(lavender), 2);
assert.equal(Math.round(workbench.liquidAdditionWeight(lavender) * 10000) / 10000, 95.2);
assert.equal(Math.round(lavender.percentage * 10000) / 10000, 9.52);
assert.equal(workbench.liquidAdditionShare(clay), 1.0504);
assert.equal(workbench.liquidAdditionWeight(clay), 50);
assert.equal(Math.round(workbench.liquidAdditionShareTotal() * 10000) / 10000, 3.0504);

const rows = serializeDraft(workbench).phase_items;
assert.equal(rows.fragrance[0].final_percentage, 2);
assert.equal(Object.hasOwn(rows.additives[0], 'final_percentage'), false);

// A share saved with the formula comes back on the row and is sent again.
const saved = draftStateFromDraft({ phases: workbench.phaseOrder, phaseItems: { fragrance: [{ id: 'lavender', ingredient_id: 10, name: 'Lavender', percentage: 9.52, weight: 95.2, final_percentage: 2 }] } }, workbench);
Object.assign(workbench, saved);
assert.equal(workbench.liquidAdditionShare(workbench.phaseItems.fragrance[0]), 2);
assert.equal(serializeDraft(workbench).phase_items.fragrance[0].final_percentage, 2);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('heads the final composition with the anhydrous soap and lists the parts in reading order', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.backendCalculation = { liquid_process: {
    status: 'ok',
    anhydrous_soap_weight: '12.0290',
    final_anhydrous_soap_weight: '13.1960',
    final_anhydrous_percentage: '20.0000',
    composition: { soap_salt_weight: '12.0860', glycerine_weight: '1.1100', unreacted_lipids_weight: '0.0000', free_koh_weight: '0.0000', koh_impurity_weight: '0.2480', neutralization_salt_weight: '0.0000', additions_weight: '3.2990', water_weight: '49.2390' },
    composition_percentages: { soap_salt_weight: '18.3', glycerine_weight: '1.7', unreacted_lipids_weight: '0', free_koh_weight: '0', koh_impurity_weight: '0.4', neutralization_salt_weight: '0', additions_weight: '5.0', water_weight: '74.6' },
} };

assert.deepEqual(workbench.liquidAnhydrousSoap(), { percentage: '20.0000', weight: '13.1960', pasteWeight: '12.0290' });
assert.deepEqual(workbench.liquidCompositionRows().map((row) => row.key), ['soap_salt_weight', 'glycerine_weight', 'additions_weight', 'water_weight', 'koh_impurity_weight']);

workbench.backendCalculation.liquid_process.final_anhydrous_soap_weight = '12.0290';
assert.equal(workbench.liquidAnhydrousSoap().pasteWeight, null);

workbench.backendCalculation = { liquid_process: { status: 'invalid', message: 'nope' } };
assert.equal(workbench.liquidAnhydrousSoap(), null);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('shows liquid soap qualities as one row and leaves out the notes the process section covers', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const properties = {
    qualities: { dilution_ease: 33.6, bubble_volume: 31.3, creamy_lather: 30.7, lather_stability: 28.9, cleansing_strength: 20 },
    quality_applicability: { cleansing_strength: { applies: false, display: 'not_applicable' }, bubble_volume: { applies: true, display: 'tendency' } },
    warnings: ['high_koh_context_process_dependent', 'negative_superfat_requires_neutralization_and_ph_control', 'high_polyunsaturated_dos_risk'],
};
const liquid = bench(liquidFamily);
liquid.backendCalculation = { properties };

assert.deepEqual(liquid.liquidQualityRows().map((row) => [row.key, row.value]), [
    ['dilution_ease', 33.6],
    ['bubble_volume', 31.3],
    ['creamy_lather', 30.7],
    ['lather_stability', 28.9],
]);
assert.equal(liquid.liquidQualityRows()[0].level, 'qualities.low');
assert.deepEqual(liquid.qualityFlags().map((flag) => flag.label), ['qualities.high_polyunsaturated_dos']);

const highKohBar = bench(barFamily);
highKohBar.backendCalculation = { properties };
assert.equal(highKohBar.qualityFlags().length, 3);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('lists a liquid soap label composition on the finished batch, without residual water', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.backendCalculation = { liquid_process: { status: 'ok', final_weight: '100.0000', composition: { koh_impurity_weight: '0.4000' } } };
// The server counts the KOH flakes' non-KOH share in AQUA.
liquid.backendLabeling = {
    basis: { formula_weight: 100, cured_weight: null, residual_water_weight: null },
    default_variant_key: 'saponified_with_superfat',
    list_variants: [{
        key: 'saponified_with_superfat',
        ingredient_rows: [
            { label: 'AQUA', kind: 'water', weight: 72.4, percent_of_formula: 72.4, lye_liquid_weight: 72.4, source_ingredients: ['Lye and dilution water'] },
            { label: 'POTASSIUM OLIVATE', kind: 'saponified_oil', weight: 23.6, percent_of_formula: 23.6, lye_liquid_weight: 0, source_ingredients: ['Olive Oil'] },
            { label: 'GLYCERIN', kind: 'derived', weight: 4, percent_of_formula: 4, lye_liquid_weight: 0, source_ingredients: ['Saponification'] },
        ],
        declaration_rows: [],
    }],
};

const rows = liquid.curedSoapIngredientRows;
const water = rows.find((row) => row.label === 'AQUA');

assert.equal(liquid.curedSoapOutputBasisWeight, 100);
assert.ok(Math.abs(water.percent_of_cured_basis - 72.4) < 1e-9);
assert.equal(liquid.outputRowKindLabel(water), 'Water');
assert.equal(rows.length, 3);
assert.ok(Math.abs(liquid.curedSoapIngredientTotalPercent - 100) < 1e-9);

const bar = bench(barFamily);
bar.backendLabeling = { ...liquid.backendLabeling, basis: { formula_weight: 140, cured_weight: 110, residual_water_weight: 11 } };
assert.equal(bar.curedSoapOutputBasisWeight, 110);
assert.equal(bar.curedSoapIngredientRows.find((row) => row.label === 'AQUA').adjusted_weight, 11);
assert.equal(bar.outputRowKindLabel({ label: 'AQUA' }), 'Residual water');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('prices liquid soap dilution liquids from the preview and keeps them out of the water row', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.oilUnit = 'g';
liquid.oilWeight = 1000;
liquid.phaseItems.final_dilution = [{ id: 'h1', ingredient_id: 7, name: 'Lavender hydrosol', percentage: 30 }];
liquid.backendCalculation = { liquid_process: {
    status: 'ok',
    stages: { first_dilution: { add_weight: '100' }, neutralization: { add_weight: '0' }, final_dilution: { add_weight: '200' } },
    neutralizer: null,
    dilution_liquids: { first_dilution: { water_weight: 100, liquids: [] }, final_dilution: { water_weight: 140, liquids: [{ id: 'h1', ingredient_id: 7, percentage: 30, weight: 60 }] } },
} };

assert.equal(liquid.costingWeightForRow('final_dilution', liquid.phaseItems.final_dilution[0]), 60);
assert.equal(liquid.liquidDilutionWaterWeight(), 240);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('adds dilution liquids to a step and sends them as a share of its water', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.ingredients = [
    { id: 7, name: 'Lavender hydrosol', category: 'botanicals_extracts', subcategory: 'hydrosols', available_phases: ['additives'], can_dilute_liquid_soap: true },
    { id: 9, name: 'KOH', category: 'soapmaking_alkalis', available_phases: [] },
    { id: 11, name: 'Water', category: 'water_solvents_carriers', subcategory: 'water', available_phases: ['additives'] },
    { id: 12, name: 'Citric acid', category: 'ph_adjusters_buffers', available_phases: ['additives', 'neutralization'], liquid_soap_neutralizer: 'citric_acid_anhydrous' },
];
liquid.phaseItems.final_dilution = liquid.phaseItems.final_dilution ?? [];
liquid.addDilutionLiquid('final_dilution', 7);
liquid.addDilutionLiquid('final_dilution', 9);
liquid.addDilutionLiquid('final_dilution', 11);
liquid.addDilutionLiquid('final_dilution', 12);
liquid.phaseItems.final_dilution[0].percentage = 30;

assert.equal(liquid.dilutionLiquidRows('final_dilution').length, 1);
assert.equal(liquid.dilutionLiquidShareTotal('final_dilution'), 30);
assert.deepEqual(
    serializeDraft(liquid).phase_items.final_dilution.map(({ ingredient_id, percentage, weight }) => ({ ingredient_id, percentage, weight })),
    [{ ingredient_id: 7, percentage: 30, weight: 0 }],
);

liquid.backendCalculation = { liquid_process: { status: 'ok', dilution_liquids: { final_dilution: { water_weight: 140, liquids: [{ id: liquid.phaseItems.final_dilution[0].id, ingredient_id: 7, percentage: 30, weight: 60 }] } } } };
assert.equal(liquid.dilutionLiquidWeight('final_dilution', liquid.phaseItems.final_dilution[0]), 60);
assert.equal(liquid.dilutionWaterWeight('final_dilution'), 140);

const bar = bench(barFamily);
assert.equal(Object.hasOwn(serializeDraft(bar).phase_items, 'final_dilution'), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('costs a liquid soap addition at its share of the finished batch', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.oilUnit = 'g';
liquid.oilWeight = 1000;
liquid.phaseItems.fragrance = [{ id: 'lavender', ingredient_id: 10, name: 'Lavender', percentage: 9.52, weight: 95.2, final_percentage: 2 }];
liquid.backendCalculation = { liquid_process: { status: 'ok', target_final_weight: '9520.0000', additions: [] } };

assert.equal(Math.round(liquid.costingWeightForRow('fragrance', liquid.phaseItems.fragrance[0]) * 10000) / 10000, 190.4);

const bar = bench(barFamily);
bar.oilUnit = 'g';
bar.oilWeight = 1000;
assert.equal(bar.costingWeightForRow('fragrance', { id: 'f', ingredient_id: 10, percentage: 2 }), 20);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('focuses the share of a new liquid soap addition in weight mode', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
const focused = [];
const share = { focus: () => focused.push('share'), select: () => {} };
liquid.editMode = 'weight';
liquid.lastAddedIngredientRowId = 'clay';
liquid.canFocusAddedIngredientAmount = () => true;

liquid.focusAddedIngredientAmount({ querySelector: (selector) => selector === '[data-workbench-amount-input="percentage"]' ? share : null }, 'clay');

assert.deepEqual(focused, ['share']);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('keeps a dose typed while the recommendation is still loading', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.oilWeight = 1000;
workbench.phaseItems.neutralization = [{ id: 'acid', ingredient_id: 7, percentage: 0.5, weight: 5 }];
workbench.syncIngredientListVariantSelection = () => {};
const previews = [];
workbench.$wire = { previewCalculation: () => new Promise(resolve => { previews.push(resolve); }) };
const tick = () => new Promise(resolve => setTimeout(resolve, 0));

const runningPreview = workbench.refreshCalculationPreview();
const recommendation = workbench.useRecommendedLiquidNeutralizer();
workbench.setLiquidNeutralizerWeight('7.1');
previews[0]({ ok: true, calculation: { liquid_process: { neutralizer: { recommended_weight: '6.5062' } } } });
await runningPreview;
await tick();
previews[1]({ ok: true, calculation: { liquid_process: { neutralizer: { recommended_weight: '6.5062' } } } });
await recommendation;

assert.ok(Math.abs(workbench.liquidNeutralizerWeight() - 7.1) < 1e-9);
clearTimeout(workbench.calculationPreviewTimer);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('includes free fatty acids in the displayed final composition', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.backendCalculation = { liquid_process: {
    status: 'ok', composition: { soap_salt_weight: '90', free_fatty_acid_weight: '5', water_weight: '5' },
    composition_percentages: { soap_salt_weight: '90', free_fatty_acid_weight: '5', water_weight: '5' },
} };
const rows = workbench.liquidCompositionRows();
assert.deepEqual(rows.map(row => row.key), ['soap_salt_weight', 'free_fatty_acid_weight', 'water_weight']);
assert.equal(rows.find(row => row.key === 'free_fatty_acid_weight').labelKey, 'liquid.components.free_fatty_acid_weight');
assert.ok(rows.every(row => row.color));
assert.equal(rows.reduce((sum, row) => sum + Number(row.share), 0), 100);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('keeps the first saved dose and calculation when an older preview finishes later', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.recipeId = 1;
workbench.oilWeight = 1000;
workbench.phaseItems.neutralization = [{ id: 'acid', ingredient_id: 7, percentage: 0.5, weight: 5 }];
workbench.syncIngredientListVariantSelection = () => {};
workbench.flushCostingSave = async () => true;
workbench.queueRevisionMutation = async action => action();
workbench.activateSavedRecipeEditing = async () => {};
const saved = [];
let finishOldPreview;
const latest = { liquid_process: { status: 'ok', final_weight: '60138.7500', neutralizer: { weight: '199.0000' } } };
workbench.$wire = {
    previewCalculation: () => new Promise(resolve => { finishOldPreview = resolve; }),
    save: async draft => {
        saved.push(structuredClone(draft));
        return { ok: true, snapshot: {
            draft: { oilWeight: workbench.oilWeight, superfat: workbench.superfat, phaseItems: structuredClone(draft.phase_items) },
            calculation: latest,
        } };
    },
};
const oldPreview = workbench.refreshCalculationPreview();
workbench.oilWeight = 10000;
workbench.superfat = -10;
workbench.setLiquidNeutralizerWeight('199');
await workbench.save();
assert.equal(saved.length, 1);
assert.equal(saved[0].phase_items.neutralization[0].weight, 199);
assert.equal(workbench.backendCalculation.liquid_process.final_weight, '60138.7500');
finishOldPreview({ ok: true, calculation: { liquid_process: { status: 'acid_exceeds_free_koh', final_weight: null } } });
await oldPreview;
assert.equal(workbench.backendCalculation.liquid_process.final_weight, '60138.7500');
assert.equal(workbench.liquidNeutralizerWeight(), 199);
await workbench.save();
assert.equal(saved[1].phase_items.neutralization[0].weight, 199);
assert.equal(workbench.backendCalculation.liquid_process.final_weight, '60138.7500');
clearTimeout(workbench.calculationPreviewTimer);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
