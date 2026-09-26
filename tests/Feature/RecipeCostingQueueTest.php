<?php

use Symfony\Component\Process\Process;

it('coalesces newer costing input and accepts each successful revision without overwriting it', function (): void {
    runCostingQueueScenario(<<<'JS'
const first = deferred();
const second = deferred();
const firstStarted = deferred();
const secondStarted = deferred();
const sent = [];
workbench.$wire.saveCosting = async (payload) => {
    sent.push({ units: payload.units_produced, revision: workbench.editingRecipeRevision });
    if (sent.length === 1) {
        firstStarted.resolve();
        return first.promise;
    }
    secondStarted.resolve();
    return second.promise;
};
workbench.costingUnitsProduced = 2;
workbench.scheduleCostingSave();
const flushed = workbench.flushCostingSave();
await firstStarted.promise;
workbench.costingUnitsProduced = 3;
workbench.scheduleCostingSave();
workbench.costingUnitsProduced = 4;
workbench.scheduleCostingSave();
first.resolve(saved(5, 2));
await secondStarted.promise;
assert.equal(workbench.costingUnitsProduced, 4);
assert.equal(workbench.editingRecipeRevision, 5);
assert.deepEqual(sent, [{ units: 2, revision: 4 }, { units: 4, revision: 5 }]);
assert.equal(registry.blocksNavigation(), true);
second.resolve(saved(6, 4));
assert.equal(await flushed, true);
assert.equal(workbench.editingRecipeRevision, 6);
assert.equal(workbench.costingUnitsProduced, 4);
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('keeps failed costing input dirty and stops a formula publish', function (): void {
    runCostingQueueScenario(<<<'JS'
let publishCalls = 0;
workbench.$wire.saveCosting = async () => ({ ok: false, message: 'Price unavailable' });
workbench.$wire.publish = async () => { publishCalls++; return { ok: true }; };
workbench.costingUnitsProduced = 17;
workbench.scheduleCostingSave();
await workbench.persist('publish');
assert.equal(publishCalls, 0);
assert.equal(workbench.costingUnitsProduced, 17);
assert.equal(workbench.editingRecipeRevision, 4);
assert.equal(workbench.costingSaveStatus, 'error');
assert.equal(registry.blocksNavigation(), true);
JS);
});

it('preserves input entered during costing load and blocks rebasing its queued save', function (): void {
    runCostingQueueScenario(<<<'JS'
const response = deferred();
const started = deferred();
let saveCalls = 0;
workbench.$wire.loadCosting = async () => { started.resolve(); return response.promise; };
workbench.$wire.saveCosting = async () => { saveCalls++; return saved(5, 8); };
const loading = workbench.ensureCostingLoaded(true);
await started.promise;
workbench.costingUnitsProduced = 8;
workbench.scheduleCostingSave();
const saving = workbench.flushCostingSave();
response.resolve(saved(4, 3, 9));
assert.equal(await loading, false);
assert.equal(await saving, false);
assert.equal(workbench.costingUnitsProduced, 8);
assert.equal(workbench.editingCostingRevision, 2);
assert.equal(workbench.editingStale, true);
assert.equal(saveCalls, 0);
assert.equal(registry.blocksNavigation(), true);
JS);
});

it('replaces old local prices only when a matching costing load is accepted', function (): void {
    runCostingQueueScenario(<<<'JS'
Object.defineProperty(workbench, 'costingFormulaRows', { get: () => [{
    rowId: 'oil', ingredient_id: 10, phaseKey: 'oils', position: 0, defaultPricePerKg: 1,
}] });
workbench.costingOilUnit = 'kg';
workbench.costingPriceByRowId = { oil: 2 };
workbench.$wire.loadCosting = async () => ({
    ...saved(4, 3, 9),
    costing: {
        settings: { unitsProduced: 3 },
        item_prices: [{ ingredient_id: 10, phase_key: 'oils', position: 0, price_per_kg: 7 }],
        packaging_items: [],
    },
});
assert.equal(await workbench.ensureCostingLoaded(true), true);
assert.equal(workbench.costingPriceByRowId.oil, 7);
assert.equal(workbench.editingCostingRevision, 9);
assert.equal(workbench.editingRecipeRevision, 4);
assert.equal(registry.blocksNavigation(), false);
JS);
});

function runCostingQueueScenario(string $scenario): void
{
    $bootstrap = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

// Resolve the same extensionless local imports Vite accepts, keeping production modules intact.
const modules = new Map();
function loadModule(filename) {
    filename = path.resolve(filename);
    if (!modules.has(filename)) {
        modules.set(filename, new vm.SourceTextModule(fs.readFileSync(filename, 'utf8'), { identifier: filename }));
    }
    return modules.get(filename);
}
const entry = loadModule('resources/js/recipe-workbench/component.js');
await entry.link((specifier, referencing) => {
    const filename = path.resolve(path.dirname(referencing.identifier), specifier);
    return loadModule(path.extname(filename) ? filename : `${filename}.js`);
});
await entry.evaluate();
const registryModule = loadModule('resources/js/dirty-state-registry.js');
await registryModule.link(() => {});
await registryModule.evaluate();
const registry = registryModule.namespace.createDirtyStateRegistry();
globalThis.window = { location: { hash: '' } };
const baseline = { recipe_revision: 4, current_version_id: 12, costing_revision: 2, status: 'acquired' };
const workbench = entry.namespace.createRecipeWorkbench({
    canPersist: true, recipe: { id: 30, current_version_id: 12 }, editing: baseline,
    ingredients: [], phases: [],
}, () => registry);
workbench.editingStatus = 'acquired';
workbench.editingOwnsLease = true;
workbench.$wire = { async releaseEditing() { return { ok: true }; } };
function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}
function saved(revision, units, costingRevision = revision) {
    return {
        ok: true,
        editing: { ...baseline, recipe_revision: revision, costing_revision: costingRevision },
        costing: { settings: { unitsProduced: units }, item_prices: [], packaging_items: [] },
    };
}
JS;

    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', $bootstrap."\n".$scenario], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
}
