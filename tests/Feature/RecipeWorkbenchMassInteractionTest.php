<?php

use Symfony\Component\Process\Process;

it('defaults only new soap formulas to 30 percent lye concentration', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
    matchMedia: () => ({ matches: false }),
};

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createRecipeWorkbenchState = createRecipeWorkbenchState;`);

const dirtyStateRegistry = { blocksNavigation: () => false };
const soap = globalThis.createRecipeWorkbenchState({
    productFamily: { slug: 'soap' },
}, dirtyStateRegistry);
const cosmetic = globalThis.createRecipeWorkbenchState({
    productFamily: { slug: 'cosmetic' },
}, dirtyStateRegistry);

assert.equal(soap.waterMode, 'lye_concentration');
assert.equal(soap.waterValue, 30);
assert.equal(cosmetic.waterMode, 'percent_of_oils');
assert.equal(cosmetic.waterValue, 38);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('converts formula state without changing its physical mass or percentages', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { MASS_UNITS, convertMass, preferredMassUnit } from './resources/js/recipe-workbench/mass.js';

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
    matchMedia: () => ({ matches: false }),
    setTimeout,
    clearTimeout,
};
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US' },
    configurable: true,
});

const assertClose = (actual, expected, tolerance = 1e-12) => {
    assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} is not within ${tolerance} of ${expected}`);
};

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createRecipeWorkbenchState = createRecipeWorkbenchState;`);

assert.equal(preferredMassUnit(1000, 'metric'), 'kg');
assert.equal(preferredMassUnit(100, 'us_customary'), 'oz');
assertClose(convertMass(1, 'kg', 'oz'), 35.27396194958041);
assertClose(convertMass(1, 'kg', 'lb'), 2.2046226218487757);
assert.equal(convertMass('1,5', 'kg', 'g'), 1500);
assertClose(convertMass(convertMass(1, 'kg', 'oz'), 'oz', 'g'), 1000, 1e-9);
assertClose(convertMass(convertMass(1, 'kg', 'lb'), 'lb', 'g'), 1000, 1e-9);

const state = globalThis.createRecipeWorkbenchState({
    productFamily: { slug: 'soap' },
    preferredMassUnit: 'kg',
}, { blocksNavigation: () => false });
state.scheduleCalculationPreview = () => {};
state.phaseItems.saponified_oils = [{ id: 'olive', percentage: 60 }];

const percentage = state.phaseItems.saponified_oils[0].percentage;
state.changeOilUnit('lb');

assert.equal(state.oilUnit, 'lb');
assertClose(state.oilWeight, 2.2046226218487757);
assert.equal(state.phaseItems.saponified_oils[0].percentage, percentage);
assert.ok(Math.abs(((state.oilWeight * 0.6) * 453.59237) - 600) < 0.000001);

state.changeOilUnit('kg');

assert.equal(state.oilUnit, 'kg');
assertClose(state.oilWeight, 1, 1e-9);

state.changeOilUnit('stone');
assert.equal(state.oilUnit, 'kg');
assertClose(state.oilWeight, 1, 1e-9);

const localizedState = globalThis.createRecipeWorkbenchState({
    productFamily: { slug: 'soap' },
    preferredMassUnit: 'kg',
}, { blocksNavigation: () => false });
localizedState.scheduleCalculationPreview = () => {};
localizedState.oilWeight = '1,5';
localizedState.changeOilUnit('g');

assert.equal(localizedState.oilUnit, 'g');
assert.equal(localizedState.oilWeight, 1500);
assert.equal(typeof localizedState.oilWeight, 'number');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('rebalances formula percentages from edited weights without changing the oil basis', function (): void {
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

const {
    updateFormulaPercentagesFromWeights,
    updateOilPercentagesFromWeights,
    updatePercentageFromWeight,
} = await import('./resources/js/recipe-workbench/calculation.js');

const cosmeticRows = [
    { id: 'water', percentage: 60 },
    { id: 'oil', percentage: 40 },
];
const cosmeticResult = updateFormulaPercentagesFromWeights(cosmeticRows, 100, 'water', 30);

assert.equal(cosmeticResult.totalWeight, 70);
assert.equal(cosmeticResult.percentagesByRowId.get('water'), 42.857);
assert.equal(cosmeticResult.percentagesByRowId.get('oil'), 57.143);

const soapOils = [
    { id: 'olive', percentage: 75 },
    { id: 'coconut', percentage: 25 },
];
const soapResult = updateOilPercentagesFromWeights(soapOils, 1000, 'olive', 500);

assert.equal(soapResult.oilWeight, 750);
assert.equal(soapResult.percentagesByRowId.get('olive'), 66.667);
assert.equal(soapResult.percentagesByRowId.get('coconut'), 33.333);

const totalOilWeight = 1000;
const soapAdditionWeight = 250;
const percentage = updatePercentageFromWeight(totalOilWeight, soapAdditionWeight);

assert.equal(percentage, 25);
assert.equal(totalOilWeight, 1000);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('uses conversion actions and all four mass units in both formula benches', function (): void {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));

    expect($source)->not->toContain("@click=\"oilUnit = '")
        ->and(substr_count($source, "changeOilUnit('g')"))->toBe(2)
        ->and(substr_count($source, "changeOilUnit('kg')"))->toBe(2)
        ->and(substr_count($source, "changeOilUnit('oz')"))->toBe(2)
        ->and(substr_count($source, "changeOilUnit('lb')"))->toBe(2);
});

it('converts the costing override without changing the calculated cost', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { convertMass, convertMassPrice } from './resources/js/recipe-workbench/mass.js';

const assertClose = (actual, expected, tolerance = 1e-12) => {
    assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} is not within ${tolerance} of ${expected}`);
};

const nonNegativeNumber = (value) => Math.max(0, Number(value) || 0);
const number = (value) => Number(value) || 0;
const parseDecimalInput = number;
const roundTo = (value, precision) => Number(Number(value).toFixed(precision));
const formatDecimalInput = (value) => String(Number(value));
const rowWeightForOilWeight = (oilWeight, row) => oilWeight * (nonNegativeNumber(row.percentage) / 100);
const MASS_UNITS = ['g', 'kg', 'oz', 'lb'];

const source = fs
    .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createCostingSection = createCostingSection;`);

const state = {
    costingOilWeight: 1,
    costingOilUnit: 'kg',
    oilWeight: 1,
    oilUnit: 'kg',
    phaseItems: {
        saponified_oils: [{
            id: 'olive',
            ingredient_id: 1,
            name: 'Olive oil',
            percentage: 100,
        }],
    },
    phaseOrder: [{ key: 'saponified_oils', name: 'Saponified oils' }],
    isCosmeticFormula: false,
    costingPriceByRowId: { olive: 10 },
    packagingCostRows: [],
    costingUnitsProduced: 1,
    ingredientForRow: () => ({ default_price_per_kg: 10 }),
    t: (key) => key,
};

Object.defineProperties(
    state,
    Object.getOwnPropertyDescriptors(globalThis.createCostingSection({})),
);
state.scheduleCostingSave = () => {};

const initialCost = state.totalBatchCost;
state.changeCostingUnit('lb');

assert.equal(state.costingOilUnit, 'lb');
assertClose(state.costingOilWeight, 2.2046226218487757);
assert.equal(state.costingPriceUnit, 'lb');
assert.equal(state.costingPriceForRow(state.costingFormulaRows[0]), 4.5359237);
assert.equal(state.canonicalPricePerKg(state.costingFormulaRows[0]), 10);
assert.ok(Math.abs(state.totalBatchCost - initialCost) < 0.000001);

state.changeCostingUnit('kg');
assertClose(state.costingOilWeight, 1, 1e-9);
assert.equal(state.costingPriceUnit, 'kg');
assert.equal(state.costingPriceForRow(state.costingFormulaRows[0]), 10);
assert.equal(state.totalBatchCost, initialCost);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('preserves alkali masses percentages and costs when changing costing units', function (): void {
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

const { createCostingSection } = await import('./resources/js/recipe-workbench/sections/costing-section.js');
const gramsPerUnit = { g: 1, kg: 1000, oz: 28.349523125, lb: 453.59237 };
const assertClose = (actual, expected) => {
    assert.ok(Math.abs(actual - expected) < 1e-7, `${actual} differs from ${expected}`);
};

for (const useBackend of [true, false]) {
    for (const [formulaUnit, gramsPerFormulaUnit] of Object.entries(gramsPerUnit)) {
        for (const lyeType of ['naoh', 'koh', 'dual']) {
            for (const batchGrams of [null, 1000, 2000]) {
                const naohGrams = lyeType === 'koh' ? 0 : 146.5928 * (lyeType === 'dual' ? 0.6 : 1);
                const kohGrams = lyeType === 'naoh' ? 0 : (205.6 / 0.9) * (lyeType === 'dual' ? 0.4 : 1);
                const oil = { id: 'coconut', ingredient_id: 1, name: 'Coconut oil', percentage: 100, koh_sap_value: 0.257 };
                const state = {
                    oilWeight: 1000 / gramsPerFormulaUnit,
                    oilUnit: formulaUnit,
                    oilRows: [oil],
                    superfat: 20,
                    lyeType,
                    kohPurity: 90,
                    dualKohPercentage: 40,
                    waterMode: 'percent_of_oils',
                    waterValue: 38,
                    backendCalculation: useBackend ? {
                        lye: { selected: {
                            naoh_weight: naohGrams / gramsPerFormulaUnit,
                            koh_to_weigh: kohGrams / gramsPerFormulaUnit,
                        } },
                    } : null,
                    costingOilWeight: batchGrams,
                    costingOilUnit: 'g',
                    costingUnitsProduced: 12,
                    costingAlkaliIngredients: {
                        naoh: { ingredient_id: 2, name: 'NaOH', default_price_per_kg: 3 },
                        koh: { ingredient_id: 3, name: 'KOH', default_price_per_kg: 6 },
                    },
                    phaseItems: { saponified_oils: [oil] },
                    phaseOrder: [],
                    isCosmeticFormula: false,
                    costingPriceByRowId: { coconut: 12 },
                    packagingCostRows: [],
                    ingredientForRow: () => ({ default_price_per_kg: 12 }),
                    t: (key) => key,
                };
                Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));
                state.scheduleCostingSave = () => {};

                const scale = (batchGrams ?? 1000) / 1000;
                const expectedCost = (12 + naohGrams / 1000 * 3 + kohGrams / 1000 * 6) * scale;

                for (const unit of ['g', 'kg', 'oz', 'lb', 'g']) {
                    state.changeCostingUnit(unit);

                    assertClose(state.costingBaseOilWeight * gramsPerUnit[unit], 1000 * scale);
                    const alkaliRows = state.costingAlkaliRows();
                    assert.equal(alkaliRows.length, lyeType === 'dual' ? 2 : 1);
                    for (const row of alkaliRows) {
                        const expectedGrams = row.ingredient_id === 2 ? naohGrams : kohGrams;
                        assert.equal(row.weightUnit, unit);
                        assertClose(row.weight * gramsPerUnit[unit], expectedGrams * scale);
                        assertClose(row.percentage, expectedGrams / 10);
                    }
                    assertClose(state.totalBatchCost, expectedCost);
                    assertClose(state.costPerUnit, expectedCost / 12);
                }
            }
        }
    }
}
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('uses costing conversion actions for all four mass units', function (): void {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php'));

    expect($source)->not->toContain("@click=\"costingOilUnit = '")
        ->and($source)->toContain(
            "changeCostingUnit('g')",
            "changeCostingUnit('kg')",
            "changeCostingUnit('oz')",
            "changeCostingUnit('lb')",
        );
});

it('renders the active costing price basis instead of a fixed kilogram label', function (): void {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php'));

    expect($source)->toContain(
        "t('costing.ingredients.price', { unit: costingPriceUnit })",
        "t('costing.accessibility.price_for', { item: row.name, unit: costingPriceUnit })",
        'format(costingOilWeight, 2)',
        'format(costingPriceForRow(row), 2)',
    );
});

it('labels the costing KOH row with the live purity without changing its identity', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { convertMass, convertMassPrice } from './resources/js/recipe-workbench/mass.js';

const nonNegativeNumber = (value) => Math.max(0, Number(value) || 0);
const number = (value) => Number(value) || 0;
const parseDecimalInput = number;
const roundTo = (value, precision) => Number(Number(value).toFixed(precision));
const formatDecimalInput = (value) => String(Number(value));
const rowWeightForOilWeight = (oilWeight, row) => oilWeight * (nonNegativeNumber(row.percentage) / 100);
const MASS_UNITS = ['g', 'kg', 'oz', 'lb'];

const source = fs
    .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createCostingSection = createCostingSection;`);

const translations = {
    costing: {
        ingredients: {
            koh_with_purity: ':name (KOH :purity%)',
        },
    },
};

const state = {
    isCosmeticFormula: false,
    lyeType: 'koh',
    kohPurity: 90.5,
    numberLocale: 'en_US',
    costingAlkaliIngredients: {
        koh: { ingredient_id: 7, name: 'Potassium hydroxide', default_price_per_kg: 12 },
    },
    backendCalculation: {
        lye: { selected: { naoh_weight: 0, koh_to_weigh: 148.6 } },
    },
    costingOilWeight: 1,
    costingOilUnit: 'kg',
    oilWeight: 1,
    oilUnit: 'kg',
    format: (value, decimals = 2) => Number(value).toFixed(decimals),
    t: (path, replacements = {}) => {
        const value = path.split('.').reduce((copy, segment) => copy?.[segment], translations);
        const text = typeof value === 'string' ? value : path;

        return Object.entries(replacements).reduce(
            (translated, [key, replacement]) => translated.replaceAll(`:${key}`, String(replacement)),
            text,
        );
    },
};

Object.defineProperties(
    state,
    Object.getOwnPropertyDescriptors(globalThis.createCostingSection({})),
);

const rowAtNinetyPointFive = state.costingAlkaliRows()[0];
assert.equal(rowAtNinetyPointFive.name, 'Potassium hydroxide (KOH 90.5%)');
assert.equal(rowAtNinetyPointFive.weight, 148.6);

state.kohPurity = 100;
const rowAtHundred = state.costingAlkaliRows()[0];
assert.equal(rowAtHundred.name, 'Potassium hydroxide (KOH 100%)');
assert.equal(rowAtHundred.ingredient_id, rowAtNinetyPointFive.ingredient_id);
assert.equal(rowAtHundred.phaseKey, 'lye_alkali');
assert.equal(rowAtHundred.position, rowAtNinetyPointFive.position);
assert.equal(rowAtHundred.weight, rowAtNinetyPointFive.weight);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});
