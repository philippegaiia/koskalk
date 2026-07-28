<?php

use Symfony\Component\Process\Process;

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

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createRecipeWorkbenchState = createRecipeWorkbenchState;`);

assert.equal(preferredMassUnit(1000, 'metric'), 'kg');
assert.equal(preferredMassUnit(100, 'us_customary'), 'oz');
assert.equal(convertMass(1, 'kg', 'lb'), 2.204622622);

const state = globalThis.createRecipeWorkbenchState({
    productFamily: { slug: 'soap' },
    preferredMassUnit: 'kg',
}, { blocksNavigation: () => false });
state.scheduleCalculationPreview = () => {};
state.phaseItems.saponified_oils = [{ id: 'olive', percentage: 60 }];

const percentage = state.phaseItems.saponified_oils[0].percentage;
state.changeOilUnit('lb');

assert.equal(state.oilUnit, 'lb');
assert.equal(state.oilWeight, 2.204622622);
assert.equal(state.phaseItems.saponified_oils[0].percentage, percentage);
assert.ok(Math.abs(((state.oilWeight * 0.6) * 453.59237) - 600) < 0.000001);

state.changeOilUnit('kg');

assert.equal(state.oilUnit, 'kg');
assert.equal(state.oilWeight, 1);

state.changeOilUnit('stone');
assert.equal(state.oilUnit, 'kg');
assert.equal(state.oilWeight, 1);
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
import { convertMass } from './resources/js/recipe-workbench/mass.js';

const nonNegativeNumber = (value) => Math.max(0, Number(value) || 0);
const number = (value) => Number(value) || 0;
const parseDecimalInput = number;
const roundTo = (value, precision) => Number(Number(value).toFixed(precision));
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
assert.equal(state.costingOilWeight, 2.204622622);
assert.ok(Math.abs(state.totalBatchCost - initialCost) < 0.000001);

state.changeCostingUnit('kg');
assert.equal(state.costingOilWeight, 1);
assert.equal(state.totalBatchCost, initialCost);
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
