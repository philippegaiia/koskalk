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
workbench.useRecommendedLiquidNeutralizer();
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

workbench.setLiquidAdditionWeight(lavender, '80');
assert.equal(lavender.final_percentage, null);
assert.equal(Object.hasOwn(serializeDraft(workbench).phase_items.fragrance[0], 'final_percentage'), false);
assert.equal(lavender.percentage, 8);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('shows the anhydrous soap weight from the server preview, and the soap a fatty acid adds', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = createRecipeWorkbench({
    productFamily: liquidFamily,
    numberLocaleOptions: { en_US: '1,234.56' },
    translations: { liquid: {
        anhydrous_soap_weight: 'Anhydrous soap: :amount :unit',
        anhydrous_soap_weight_neutralized: 'Anhydrous soap: :paste :unit from the paste, :final :unit after neutralization',
    } },
});
workbench.backendCalculation = { liquid_process: { status: 'ok', anhydrous_soap_weight: '1190.0000', final_anhydrous_soap_weight: '1190.0000' } };
assert.equal(workbench.liquidAnhydrousSoapLabel(), 'Anhydrous soap: 1190 g');

workbench.backendCalculation = { liquid_process: { status: 'ok', anhydrous_soap_weight: '1190.0000', final_anhydrous_soap_weight: '1222.7745' } };
assert.equal(workbench.liquidAnhydrousSoapLabel(), 'Anhydrous soap: 1190 g from the paste, 1223 g after neutralization');

workbench.backendCalculation = { liquid_process: { status: 'invalid', message: 'nope' } };
assert.equal(workbench.liquidAnhydrousSoapLabel(), '');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
