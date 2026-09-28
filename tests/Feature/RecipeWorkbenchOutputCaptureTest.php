<?php

use Symfony\Component\Process\Process;

it('captures a detached current draft and nullable costing without saving or rebasing state', function (): void {
    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const modules = new Map();
function loadModule(filename) {
    filename = path.resolve(filename);
    if (!modules.has(filename)) {
        modules.set(filename, new vm.SourceTextModule(fs.readFileSync(filename, 'utf8'), { identifier: filename }));
    }
    return modules.get(filename);
}

const entry = loadModule('resources/js/recipe-workbench/outputs.js');
await entry.link((specifier, referencing) => {
    const filename = path.resolve(path.dirname(referencing.identifier), specifier);
    return loadModule(path.extname(filename) ? filename : `${filename}.js`);
});
await entry.evaluate();

let saveCalls = 0;
let flushCalls = 0;
const state = {
    formulaName: 'Working Soap Formula',
    oilUnit: 'g',
    oilWeight: 750,
    manufacturingMode: 'saponify_in_formula',
    exposureMode: 'rinse_off',
    regulatoryRegime: 'eu',
    editMode: 'percentage',
    lyeType: 'naoh',
    kohPurity: 90,
    dualKohPercentage: 40,
    waterMode: 'percent_of_oils',
    waterValue: 38,
    superfat: 5,
    ifraCategorySelectionMode: 'automatic',
    selectedIfraProductCategoryId: '',
    finalIngredientList: '',
    finalIngredientListBasisHash: '',
    finalPlainIngredientList: '',
    finalPlainIngredientListBasisHash: '',
    phaseOrder: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
    phaseItems: { saponified_oils: [{ id: 'oil-row', ingredient_id: 12, percentage: 100, note: 'live' }], lye_water: [] },
    packagingPlanRows: [],
    productionOutputType: 'finished_product',
    outputIngredientId: '',
    costingOilWeight: 750,
    costingOilUnit: 'g',
    costingUnitsProduced: 3,
    costingCurrency: 'EUR',
    costingFormulaRows: [{ id: 88, ingredient_id: 12, phaseKey: 'saponified_oils', position: 1 }],
    packagingCostRows: [
        { packaging_item_id: 7, name: 'Jar', unit_cost: null, quantity: 1 },
        { packaging_item_id: 7, name: 'Jar', unit_cost: null, quantity: 2 },
    ],
    hasLoadedCosting: true,
    canonicalPricePerKg() { return null; },
    editingRecipeRevision: 9,
    save() { saveCalls++; },
    flushCostingSave() { flushCalls++; },
    async $nextTick() {
        this.$wire.data.manufacturing_instructions = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Latest procedure' }] }] };
    },
    $wire: { data: { manufacturing_instructions: { type: 'doc', content: [] } } },
};

const captured = await entry.namespace.captureRecipeWorkbenchOutput(state, {
    workspace: 'workspace-public-id',
    recipe: 'recipe-public-id',
    productFamily: 'soap',
    printUrl: '/dashboard/formula-outputs/print',
    exportUrl: '/dashboard/formula-outputs/export',
}, { format: 'csv', printContext: 'instructions' });

assert.equal(captured.workspace, 'workspace-public-id');
assert.equal(captured.recipe, 'recipe-public-id');
assert.equal(captured.product_family, 'soap');
assert.equal(captured.draft.oil_weight, 750);
assert.equal(captured.manufacturing_instructions.content[0].content[0].text, 'Latest procedure');
assert.equal(captured.costing.oil_weight_for_costing, 750);
assert.equal(captured.costing.oil_unit_for_costing, 'g');
assert.equal(captured.costing.units_produced, 3);
assert.equal(captured.costing.currency, 'EUR');
assert.equal(captured.costing.items[0].id, 88);
assert.equal(captured.costing.items[0].ingredient_id, 12);
assert.equal(captured.costing.items[0].phase_key, 'saponified_oils');
assert.equal(captured.costing.items[0].position, 1);
assert.equal(captured.costing.items[0].price_per_kg, null);
assert.equal(captured.costing.packaging_items.length, 2);
assert.equal(captured.costing.packaging_items[0].unit_cost, null);
assert.equal(state.editingRecipeRevision, 9);
assert.equal(saveCalls, 0);
assert.equal(flushCalls, 0);

state.phaseItems.saponified_oils[0].percentage = 1;
state.$wire.data.manufacturing_instructions.content[0].content[0].text = 'changed after capture';
state.packagingCostRows[0].quantity = 99;
assert.equal(captured.draft.phase_items.saponified_oils[0].percentage, 100);
assert.equal(captured.manufacturing_instructions.content[0].content[0].text, 'Latest procedure');
assert.equal(captured.costing.packaging_items[0].components_per_unit, 1);

const rawState = { ...state };
delete rawState.$wire;
delete rawState.$nextTick;
const section = entry.namespace.createRecipeWorkbenchOutputSection({
    workspace: 'workspace-public-id',
    recipe: 'recipe-public-id',
    productFamily: 'soap',
    printUrl: '/dashboard/formula-outputs/print',
    exportUrl: '/dashboard/formula-outputs/export',
});
const invocationProxy = {
    ...rawState,
    ...section,
    $wire: { data: { manufacturing_instructions: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Proxy procedure' }] }] } } },
    async $nextTick() {},
};
const proxyCapture = await invocationProxy.captureOutput({ format: 'csv' });
assert.equal(proxyCapture.manufacturing_instructions.content[0].content[0].text, 'Proxy procedure');

const unloadedCosting = await entry.namespace.captureRecipeWorkbenchOutput({ ...state, hasLoadedCosting: false }, {
    workspace: 'workspace-public-id', productFamily: 'soap', printUrl: '/print', exportUrl: '/export',
}, { format: 'csv' });
assert.equal(unloadedCosting.costing, null);
JS], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
