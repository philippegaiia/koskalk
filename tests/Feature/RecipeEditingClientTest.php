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

it('does not submit writes or editing controls for a read-only formula visitor', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/recipe-workbench/editing.js`).href;
const { createEditingSection } = await import(moduleUrl);
let writes = 0;
const viewer = Object.assign(createEditingSection({
    canPersist: true,
    canEditRecipe: false,
    recipe: { id: 41 },
    editing: null,
}), {
    isFormulaLocked: false,
    t: (key) => key,
});

assert.equal(viewer.editingRequired, false);
assert.equal(viewer.canWriteRecipe, false);
assert.equal(viewer.canSubmitRecipeControl, false);
const response = await viewer.queueRevisionMutation(async () => {
    writes += 1;
    return { ok: true };
});
assert.equal(response.ok, false);
assert.equal(writes, 0);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('uses secure random bytes for editing on browsers without randomUUID', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
Object.defineProperty(globalThis, 'crypto', { configurable: true, value: {
    getRandomValues: webcrypto.getRandomValues.bind(webcrypto),
} });
const payload = { canPersist: true, recipe: { id: 1 }, editing: {} };
const first = createEditingSection(payload);
const second = createEditingSection(payload);
assert.match(first.editingToken, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
assert.notEqual(first.editingToken, second.editingToken);
let sentToken;
Object.assign(first, { t: key => key, $wire: { async beginEditing(token) {
    sentToken = token;
    return { ok: true, editing: { status: 'acquired' } };
} } });
await first.retryEditing();
assert.equal(sentToken, first.editingToken);
assert.equal(first.editingStatus, 'acquired');
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('never sends missing tokens on retry or takeover when browser crypto is unavailable', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
Object.defineProperty(globalThis, 'crypto', { configurable: true, value: undefined });
let calls = 0;
const workbench = Object.assign(createEditingSection({ canPersist: true, recipe: { id: 1 }, editing: { can_take_over: true } }), {
    t: key => key,
    $wire: { async beginEditing() { calls++; }, async takeoverEditing() { calls++; } },
});
await workbench.retryEditing();
assert.equal(calls, 0);
assert.equal(workbench.editingMessage, 'editing.token_unavailable');
workbench.editingStatus = 'blocked';
workbench.editingTakeoverReason = 'Recover editing';
await workbench.confirmEditingTakeover();
assert.equal(calls, 0);
assert.equal(workbench.canTakeOverEditing, false);
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
