<?php

use Symfony\Component\Process\Process;

function runRecipeWorkbenchSuperfatScript(string $assertions): Process
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

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
};
global.document = {
    addEventListener() {},
    removeEventListener() {},
};
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const { persistWorkbench, saveErrorMessages } = await import('./resources/js/recipe-workbench/bridge.js');
const workbench = createRecipeWorkbench({
    productFamily: { slug: 'soap' },
    numberLocaleOptions: { en_US: '1,234.56' },
});
workbench.phaseItems.saponified_oils = [
    { id: 'olive', ingredient_id: 1, name: 'Olive', percentage: 100, koh_sap_value: 0.188, fatty_acid_profile: {} },
];
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$assertions),
        base_path(),
    );
    $process->run();

    return $process;
}

it('hides locally estimated lye amounts when the server rejects the soap settings', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
assert.ok(workbench.lyeSummaryCards.every((card) => card.value !== null));
assert.notEqual(workbench.lyeWaterDiagnostic.value, '—');
assert.notEqual(workbench.formatLyeLiquidMass(workbench.lyeLiquidWaterWeight()), '—');

workbench.backendCalculation = null;
workbench.calculationPreviewMessage = 'Bar soap superfat cannot be below -10%.';

assert.equal(workbench.isCalculationRejected, true);
assert.ok(workbench.lyeSummaryCards.length > 0);
assert.ok(workbench.lyeSummaryCards.every((card) => card.value === null));
assert.equal(workbench.formatLyeSummaryCardValue(workbench.lyeSummaryCards[0]), '—');
assert.equal(workbench.lyeWaterDiagnostic.value, '—');
assert.equal(workbench.lyeWaterDiagnostic.detail, 'Bar soap superfat cannot be below -10%.');
assert.equal(workbench.lyeWaterDiagnostic.tone, 'danger');
workbench.phaseItems.lye_water = [{ id: 'milk', ingredient_id: 9, name: 'Milk', percentage: 50 }];
assert.equal(workbench.formatLyeLiquidMass(workbench.lyeLiquidWeight(workbench.phaseItems.lye_water[0])), '—');
assert.equal(workbench.formatLyeLiquidMass(workbench.lyeLiquidWaterWeight()), '—');
assert.ok(workbench.totalSummaryCards
    .filter((card) => ['produced-glycerine', 'wet-weight', 'cured-weight'].includes(card.id))
    .every((card) => card.value === '—'));
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('takes the superfat indicator and effective superfat from the server calculation', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
workbench.superfat = -5;
assert.equal(workbench.superfatStatus, 'excess_lye');
assert.equal(workbench.effectiveSuperfat, null);

workbench.backendCalculation = {
    lye: {
        superfat_percentage: -5,
        effective_superfat_percentage: 4.5455,
        post_reaction_oils_weight: 100,
        superfat_status: 'normal',
    },
};
assert.equal(workbench.superfatStatus, 'normal');
assert.equal(workbench.effectiveSuperfat, 4.5455);

workbench.backendCalculation.lye.superfat_status = 'low_margin';
assert.equal(workbench.superfatStatus, 'low_margin');

workbench.backendCalculation.lye.post_reaction_oils_weight = 0;
assert.equal(workbench.effectiveSuperfat, null);
assert.deepEqual(workbench.uncountedPostReactionOils, []);
workbench.backendCalculation.lye.uncounted_post_reaction_oils = ['Bacuri Butter'];
assert.deepEqual(workbench.uncountedPostReactionOils, ['Bacuri Butter']);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('lists rejected save reasons for the dialog without editing lease conflicts', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
assert.deepEqual(saveErrorMessages({
    ok: false,
    message: 'Bar soap superfat cannot be below -10%.',
    errors: {
        draft: ['Bar soap superfat cannot be below -10%.'],
        editing_lease: ['Another tab is editing this formula.'],
    },
}), ['Bar soap superfat cannot be below -10%.']);
assert.deepEqual(saveErrorMessages({ ok: false, message: 'Unlock this formula before editing it.' }), ['Unlock this formula before editing it.']);
assert.deepEqual(saveErrorMessages({ ok: false, errors: { editing_lease: ['Lease ended.'] } }), []);

workbench.$wire = {
    data: null,
    save: async () => ({
        ok: false,
        message: 'Bar soap superfat cannot be below -10%.',
        errors: { draft: ['Bar soap superfat cannot be below -10%.'] },
    }),
};
await persistWorkbench(workbench, 'save');

assert.equal(workbench.saveStatus, 'error');
assert.deepEqual(workbench.saveErrorMessages, ['Bar soap superfat cannot be below -10%.']);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('estimates the cured weight with the formula residual water percentage', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
const wetWeight = workbench.finalBatchWeight();
const waterWeight = workbench.lyeBreakdown().water_weight;

workbench.curedWaterPercentage = 11;
assert.ok(Math.abs(workbench.curedBatchWeight() - ((wetWeight - waterWeight) / 0.89)) < 1e-9);

workbench.curedWaterPercentage = 10;
assert.ok(Math.abs(workbench.curedBatchWeight() - ((wetWeight - waterWeight) / 0.9)) < 1e-9);
assert.equal(workbench.serializeDraft().cured_water_percentage, 10);
assert.equal(workbench.curedWaterPercentageLabel, '10');

workbench.curedWaterPercentage = 10.5;
assert.equal(workbench.curedWaterPercentageLabel, '10.5');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('labels the formula settings summary through translations', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
workbench.translations = {
    common: { superfat: 'Surgraissage' },
    settings: { summary: { base: 'Base', entry: 'Saisie', lye: 'Alcali', lye_concentration: 'Concentration :value %' } },
};
workbench.waterMode = 'lye_concentration';
workbench.waterValue = 34;
workbench.superfat = -3;

const cards = Object.fromEntries(workbench.formulaSetupSummaryCards.map((card) => [card.id, card]));

assert.equal(cards['formula-superfat'].label, 'Surgraissage');
assert.equal(cards['formula-superfat'].tone, 'danger');
assert.equal(cards['formula-lye'].label, 'Alcali');
assert.equal(cards['formula-weight'].label, 'Base');
assert.ok(cards['formula-water'].value.endsWith('Concentration 34.0 %'));

workbench.backendCalculation = { lye: { superfat_status: 'low_margin' } };
assert.equal(Object.fromEntries(workbench.formulaSetupSummaryCards.map((card) => [card.id, card]))['formula-superfat'].tone, 'warning');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('hides calculated costing quantities and totals when the server rejects the soap settings', function () {
    $process = runRecipeWorkbenchSuperfatScript(<<<'JS'
workbench.costingAlkaliIngredients = { naoh: { ingredient_id: 50, name: 'Sodium hydroxide', default_price_per_kg: 5 } };
workbench.costingWaterIngredient = { ingredient_id: 51, name: 'Water', default_price_per_kg: 1 };
workbench.ingredients = [{ id: 1, default_price_per_kg: 4 }];

const rowsById = () => Object.fromEntries(workbench.costingFormulaRows.map((row) => [row.phaseKey, row]));
const alkali = rowsById().lye_alkali;
const water = rowsById().implicit_water;

assert.ok(alkali.weight > 0);
assert.notEqual(workbench.costingRowWeightLabel(alkali), '—');
assert.notEqual(workbench.costingLineCostLabel(water), '—');
assert.ok(workbench.ingredientCostTotal > 0);

workbench.backendCalculation = null;
workbench.calculationPreviewMessage = 'Bar soap superfat cannot be below -10%.';

const rejected = rowsById();
assert.equal(workbench.costingRowWeightLabel(rejected.lye_alkali), '—');
assert.equal(workbench.costingLineCostLabel(rejected.lye_alkali), '—');
assert.equal(workbench.costingRowWeightLabel(rejected.implicit_water), '—');
assert.equal(workbench.lineCostForRow(rejected.implicit_water), null);
assert.notEqual(workbench.costingRowWeightLabel(rejected.saponified_oils), '—');
assert.equal(workbench.ingredientCostTotal, null);
assert.equal(workbench.totalBatchCost, null);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});
