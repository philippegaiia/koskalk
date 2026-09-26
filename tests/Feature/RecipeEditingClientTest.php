<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('reserves saved edit surfaces and polls blocked status without reacquiring', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/recipe-workbench/editing.js`).href;
const { createEditingSection } = await import(moduleUrl);
globalThis.document = {
    visibilityState: 'visible',
    hasFocus: () => true,
    addEventListener() {},
    removeEventListener() {},
};
globalThis.window = {
    addEventListener() {},
    removeEventListener() {},
};
const revision = {
    recipe_revision: 4,
    current_version_id: 12,
    costing_revision: 2,
    is_locked: false,
    can_take_over: true,
};
let beginCalls = 0;
let statusCalls = 0;
const workbench = Object.assign(createEditingSection({
    canPersist: true,
    recipe: { id: 30 },
    editing: { ...revision, status: 'available', holder_name: null, expires_at: null },
}), {
    isFormulaLocked: false,
    t: (key) => key,
    $wire: {
        async beginEditing(token) {
            beginCalls += 1;
            assert.match(token, /^[0-9a-f-]{36}$/);

            return { ok: true, editing: { ...revision, status: 'acquired', holder_name: 'Editor', expires_at: null } };
        },
        async editingStatus() {
            statusCalls += 1;

            return { ok: true, editing: { ...revision, status: 'available', holder_name: null, expires_at: null } };
        },
        async releaseEditing() {
            return { ok: true };
        },
    },
});

await workbench.startEditingProtection();
assert.equal(workbench.editingStatus, 'acquired');
assert.equal(beginCalls, 1);

workbench.editingOwnsLease = false;
workbench.editingStatus = 'blocked';
await workbench.pollEditingState();
assert.equal(workbench.editingStatus, 'available');
assert.equal(statusCalls, 1);
assert.equal(beginCalls, 1);
workbench.destroyEditingProtection();

const newRecipe = Object.assign(createEditingSection({ canPersist: true, recipe: null, editing: null }), {
    $wire: { async beginEditing() { beginCalls += 1; } },
});
await newRecipe.startEditingProtection();
assert.equal(newRecipe.editingStatus, 'inactive');
assert.equal(beginCalls, 1);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('serializes a heartbeat behind a save and adopts only the accepted save revision', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/recipe-workbench/editing.js`).href;
const { createEditingSection } = await import(moduleUrl);
globalThis.document = { visibilityState: 'visible', hasFocus: () => true };
const initial = { recipe_revision: 7, current_version_id: 21, costing_revision: 1, status: 'acquired', holder_name: 'Editor', expires_at: null };
let heartbeatCalls = 0;
let releaseCalls = 0;
let startWrite;
let finishWrite;
const writeStarted = new Promise((resolve) => { startWrite = resolve; });
const saveResponse = new Promise((resolve) => { finishWrite = resolve; });
const workbench = Object.assign(createEditingSection({
    canPersist: true,
    recipe: { id: 31 },
    editing: initial,
}), {
    isFormulaLocked: false,
    editingStatus: 'acquired',
    editingOwnsLease: true,
    editingStarted: true,
    t: (key) => key,
    $wire: {
        async heartbeatEditing() {
            heartbeatCalls += 1;

            return { ok: true, editing: { ...initial, recipe_revision: 8 } };
        },
        async releaseEditing() {
            releaseCalls += 1;
            return { ok: true };
        },
    },
});

const save = workbench.queueRevisionMutation(async () => {
    startWrite();
    return saveResponse;
});
await writeStarted;
const nestedScope = new Proxy(workbench, {});
const heartbeat = nestedScope.pollEditingState();
await Promise.resolve();
assert.equal(heartbeatCalls, 0);

finishWrite({
    ok: true,
    editing: { ...initial, recipe_revision: 8 },
});
await Promise.all([save, heartbeat]);

assert.equal(heartbeatCalls, 1);
assert.equal(workbench.editingRecipeRevision, 8);
assert.equal(workbench.editingStatus, 'acquired');
assert.equal(workbench.editingStale, false);
assert.equal(releaseCalls, 0);

let duplicateCalls = 0;
const lockedSource = Object.assign(createEditingSection({
    canPersist: true,
    recipe: { id: 33 },
    editing: { ...initial, status: 'blocked' },
}), {
    isFormulaLocked: true,
    t: (key) => key,
});
const duplicate = await lockedSource.queueRevisionMutation(async () => {
    duplicateCalls += 1;

    return { ok: true, redirect: '/recipes/new-copy' };
}, { allowLocked: true, allowWithoutLease: true });
assert.equal(duplicate.ok, true);
assert.equal(duplicateCalls, 1);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('stops writes after a revision conflict or a lost lease without advancing the loaded revision', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/recipe-workbench/editing.js`).href;
const { createEditingSection } = await import(moduleUrl);
globalThis.document = { visibilityState: 'visible', hasFocus: () => true };
const initial = { recipe_revision: 9, current_version_id: 25, costing_revision: 3, status: 'acquired', holder_name: 'Editor', expires_at: null };
const makeWorkbench = (wire) => Object.assign(createEditingSection({
    canPersist: true,
    recipe: { id: 32 },
    editing: initial,
}), {
    isFormulaLocked: false,
    editingStatus: 'acquired',
    editingOwnsLease: true,
    editingStarted: true,
    t: (key) => key,
    $wire: wire,
});
let staleWriteCalls = 0;
let beginCalls = 0;
const stale = makeWorkbench({
    async releaseEditing() { return { ok: true }; },
});
stale.recordEditingMutation({
    ok: false,
    message: 'This product changed.',
    errors: { edit_revision: ['This product changed.'] },
});
const staleWrite = await stale.queueRevisionMutation(async () => {
    staleWriteCalls += 1;
    return { ok: true };
});
assert.equal(staleWrite.ok, false);
assert.equal(staleWriteCalls, 0);
assert.equal(stale.editingStatus, 'stale');
assert.equal(stale.editingRecipeRevision, 9);
assert.equal(stale.canWriteRecipe, false);

let statusCalls = 0;
const lost = makeWorkbench({
    async beginEditing() { beginCalls += 1; },
    async editingStatus() {
        statusCalls += 1;
        return { ok: true, editing: { ...initial, status: 'blocked', holder_name: 'Another editor' } };
    },
});
lost.recordEditingMutation({
    ok: false,
    message: 'Your editing reservation ended.',
    errors: { editing_lease: ['Your editing reservation ended.'] },
});
await lost.pollEditingState();
assert.equal(lost.editingRecipeRevision, 9);
assert.equal(lost.editingStatus, 'blocked');
assert.equal(statusCalls, 1);
assert.equal(beginCalls, 0);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
