<?php

use Symfony\Component\Process\Process;

it('starts new formulas on the workspace default market and falls back to none', function (): void {
    runLabelMarketClientScenario(<<<'JS'
const withDefault = createWorkbench({ defaultRegulatoryRegime: 'canada_2026' });
assert.equal(withDefault.regulatoryRegime, 'canada_2026');

const withoutDefault = createWorkbench({});
assert.equal(withoutDefault.regulatoryRegime, 'none');
assert.equal(withoutDefault.isMarketCheckOff, true);
JS);
});

it('previews another market on a locked formula without marking it changed', function (): void {
    runLabelMarketClientScenario(<<<'JS'
const workbench = createWorkbench({});
workbench.regulatoryRegime = 'eu';
workbench.isFormulaLocked = true;
workbench.refreshDirtyBaseline();

workbench.regulatoryRegime = 'none';
assert.equal(workbench.isLockedMarketPreview, true);
assert.equal(workbench.hasUnsavedWorkbenchChanges(), false);
assert.equal(workbench.serializeDraft().regulatory_regime, 'none', 'The preview request uses the previewed market');

workbench.regulatoryRegime = workbench.savedRegulatoryRegime;
assert.equal(workbench.isLockedMarketPreview, false);

workbench.isFormulaLocked = false;
workbench.regulatoryRegime = 'none';
assert.equal(workbench.isLockedMarketPreview, false);
assert.equal(workbench.hasUnsavedWorkbenchChanges(), true, 'An unlocked formula saves its market change');
JS);
});

it('names the no market choice in the formula summary instead of its code', function (): void {
    runLabelMarketClientScenario(<<<'JS'
const workbench = createWorkbench({ translations: { output: { market_check: { none_label: 'No market' } } } });
assert.equal(workbench.formulaSetupLabelSummary, 'No market');

workbench.regulatoryRegime = 'canada_2026';
assert.equal(workbench.formulaSetupLabelSummary, 'CANADA_2026');
JS);
});

it('duplicates a locked formula on its saved market rather than the previewed one', function (): void {
    runLabelMarketClientScenario(<<<'JS'
const workbench = createWorkbench({});
workbench.regulatoryRegime = 'eu';
workbench.isFormulaLocked = true;
workbench.refreshDirtyBaseline();
workbench.regulatoryRegime = 'none';

let duplicatedMarket = null;
workbench.persist = async () => { duplicatedMarket = workbench.serializeDraft().regulatory_regime; };
await workbench.duplicateFormula();

assert.equal(duplicatedMarket, 'eu');
assert.equal(workbench.isLockedMarketPreview, false);
JS);
});

function runLabelMarketClientScenario(string $scenario): void
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
globalThis.window = { location: { hash: '' } };
function createWorkbench(overrides) {
    const registry = registryModule.namespace.createDirtyStateRegistry();

    return entry.namespace.createRecipeWorkbench({
        canPersist: true, recipe: { id: 30, current_version_id: 12 }, editing: null,
        ingredients: [], phases: [], translations: {}, ...overrides,
    }, () => registry);
}
JS;

    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', $bootstrap."\n".$scenario], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
}
