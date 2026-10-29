<?php

use Symfony\Component\Process\Process;

function runRecipeWorkbenchSaveStateScript(string $assertions): Process
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

const navigations = [];
global.window = {
    location: { hash: '', assign: target => navigations.push(target) },
    localStorage: { getItem: () => null, setItem: () => {} },
    Livewire: { navigate: target => navigations.push(target) },
};
global.document = { addEventListener() {}, removeEventListener() {} };
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const soapFamily = { slug: 'soap', soap_process: 'bar' };
const bench = (payload = {}) => {
    const workbench = createRecipeWorkbench({
        productFamily: soapFamily,
        numberLocaleOptions: { en_US: '1,234.56' },
        canPersist: true,
        output: { workspace: 'workspace-1', recipe: null, productFamily: 'soap' },
        ...payload,
    });
    workbench.flushCostingSave = async () => true;
    workbench.startEditingProtection = async () => null;
    workbench.refreshDirtyBaseline();

    return workbench;
};
const savedDraft = (formulaName, recipe) => ({
    recipe,
    formulaName,
    oilUnit: 'g',
    oilWeight: 1000,
    phases: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
    phaseItems: { saponified_oils: [] },
});
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$assertions),
        base_path(),
    );
    $process->run();

    return $process;
}

it('updates the saved-formula state of a new formula that stays open and keeps the name typed during the save', function () {
    $process = runRecipeWorkbenchSaveStateScript(<<<'JS'
const workbench = bench();
workbench.formulaName = 'First draft';
workbench.$wire = { data: {}, publish: async () => {
    workbench.formulaName = 'Renamed while saving';

    return {
        ok: true,
        redirect: '/recipes/9',
        snapshot: { draft: savedDraft('First draft', { id: 9, current_version_id: 31, version_number: 1, is_current: true }) },
        editing: { status: 'available', recipe_revision: 1, current_version_id: 31, costing_revision: 0 },
        recipe: { id: 9, public_id: 'recipe-9', is_locked: false, has_saved_formula: true, saved_formula_url: '/recipes/9/saved' },
    };
} };

await workbench.publish();

assert.equal(workbench.saveStatus, 'success');
assert.deepEqual(navigations, [], 'A name typed during the save keeps the page open');
assert.equal(workbench.recipeId, 9);
assert.equal(workbench.currentVersionId, 31);
assert.equal(workbench.hasSavedFormula, true);
assert.equal(workbench.savedRecipeUrl, '/recipes/9/saved');
assert.equal(workbench.hasCurrentPublishedFormula, true);
assert.equal(workbench.outputConfig.recipe, 'recipe-9');
assert.equal(workbench.formulaName, 'Renamed while saving');
assert.equal(workbench.hasUnsavedWorkbenchChanges(), true);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('marks an existing formula as published after its first publish without reopening it', function () {
    $process = runRecipeWorkbenchSaveStateScript(<<<'JS'
const recipe = { id: 12, current_version_id: 40, version_number: 1, is_current: true };
const workbench = bench({
    recipe: { id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: false, saved_formula_url: null },
    output: { workspace: 'workspace-1', recipe: 'recipe-12', productFamily: 'soap' },
});
workbench.queueRevisionMutation = async operation => operation();
workbench.formulaName = 'Lavender bar';
workbench.refreshDirtyBaseline();
assert.equal(workbench.hasSavedFormula, false);

workbench.$wire = { data: {}, publish: async () => ({
    ok: true,
    redirect: '/recipes/12',
    snapshot: { draft: savedDraft('Lavender bar', { ...recipe, current_version_id: 41, version_number: 2 }) },
    recipe: { id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: true, saved_formula_url: '/recipes/12/saved' },
}) };

await workbench.publish();

assert.equal(workbench.saveStatus, 'success');
assert.deepEqual(navigations, []);
assert.equal(workbench.currentVersionNumber, 2);
assert.equal(workbench.hasSavedFormula, true);
assert.equal(workbench.savedRecipeUrl, '/recipes/12/saved');
assert.equal(workbench.hasUnsavedWorkbenchChanges(), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('keeps the saved-formula state when a save fails', function () {
    $process = runRecipeWorkbenchSaveStateScript(<<<'JS'
const workbench = bench({
    recipe: { id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: true, saved_formula_url: '/recipes/12/saved' },
});
workbench.queueRevisionMutation = async operation => operation();
workbench.$wire = { data: {}, save: async () => ({
    ok: false,
    message: 'Formula could not be saved.',
    recipe: { id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: false, saved_formula_url: null },
}) };

await workbench.save();

assert.equal(workbench.saveStatus, 'error');
assert.equal(workbench.hasSavedFormula, true);
assert.equal(workbench.savedRecipeUrl, '/recipes/12/saved');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('clears the saved-formula link when a deleted version was the last published one', function () {
    $process = runRecipeWorkbenchSaveStateScript(<<<'JS'
const workbench = bench({
    recipe: { id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: true, saved_formula_url: '/recipes/12/saved' },
});
workbench.formulaName = 'Draft in progress';

workbench.applySavedRecipeState({ id: 12, public_id: 'recipe-12', is_locked: false, has_saved_formula: false, saved_formula_url: null });

assert.equal(workbench.hasSavedFormula, false);
assert.equal(workbench.savedRecipeUrl, null);
assert.equal(workbench.formulaName, 'Draft in progress');

workbench.applySavedRecipeState(null);
assert.equal(workbench.hasSavedFormula, false, 'An event without recipe state changes nothing');
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
