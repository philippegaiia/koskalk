<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('releases only on confirmed departure and survives component teardown', function (string $departure): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
const documentEvents = new EventTarget();
const windowEvents = new EventTarget();
globalThis.document = Object.assign(documentEvents, {
    visibilityState: 'visible', hasFocus: () => true,
    querySelector: selector => {
        assert.equal(selector, 'meta[name="csrf-token"]');
        return { getAttribute: () => 'csrf-token' };
    },
});
globalThis.window = windowEvents;
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};
const requests = [];
globalThis.fetch = async (url, options) => { requests.push({ url, options }); return { ok: true }; };
const baseline = { recipe_revision: 4, current_version_id: 12, costing_revision: 0, status: 'available', release_url: '/recipes/abc/editing/release' };
let beginCalls = 0;
const workbench = { t: key => key, isFormulaLocked: false, $wire: {
    async beginEditing() { beginCalls++; return { ok: true, editing: { ...baseline, status: 'acquired' } }; },
    async editingStatus() { return { ok: true, editing: baseline }; },
    async heartbeatEditing() { return { ok: true, editing: { ...baseline, status: 'acquired' } }; },
    releaseEditing() { throw new Error('Departure must not depend on a destroyed Livewire component'); },
} };
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(createEditingSection({ canPersist: true, recipe: { id: 1 }, editing: baseline })));
await workbench.startEditingProtection();
const token = workbench.editingToken;
documentEvents.dispatchEvent(new Event('livewire:navigate', { cancelable: true }));
windowEvents.dispatchEvent(new Event('beforeunload', { cancelable: true }));
document.visibilityState = 'hidden';
documentEvents.dispatchEvent(new Event('visibilitychange'));
windowEvents.dispatchEvent(new Event('blur'));
assert.equal(requests.length, 0, 'Unconfirmed navigation and switching tabs must keep the lease');
assert.equal(workbench.canWriteRecipe, true);
document.visibilityState = 'visible';
const departure = __DEPARTURE__;
if (departure === 'destroy') {
    workbench.destroyEditingProtection();
} else {
    (departure === 'pagehide' ? windowEvents : documentEvents).dispatchEvent(new Event(departure));
}
assert.equal(requests.length, 1);
assert.equal(workbench.canWriteRecipe, false);
let writes = 0;
await workbench.queueRevisionMutation(async () => { writes++; return { ok: true }; });
assert.equal(writes, 0, 'Queued work must not run after departure');
assert.equal(requests[0].url, baseline.release_url);
assert.equal(requests[0].options.keepalive, true);
assert.equal(requests[0].options.method, 'POST');
assert.equal(requests[0].options.credentials, 'same-origin');
assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
assert.deepEqual(JSON.parse(requests[0].options.body), { token });
workbench.destroyEditingProtection();
windowEvents.dispatchEvent(new Event('pagehide'));
documentEvents.dispatchEvent(new Event('livewire:navigating'));
assert.equal(requests.length, 1, 'Navigation and destroy must not send duplicate release requests');
assert.equal(beginCalls, 1);
JS;
    $script = str_replace('__DEPARTURE__', json_encode($departure, JSON_THROW_ON_ERROR), $script);
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
})->with(['livewire:navigating', 'pagehide', 'destroy']);

it('releases late acquisitions and keeps browser-restored drafts protected', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible', hasFocus: () => true, querySelector: () => ({ getAttribute: () => 'csrf' }) });
globalThis.window = new EventTarget();
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};
const tick = () => new Promise(resolve => setImmediate(resolve));
const requests = [];
globalThis.fetch = async (...args) => { requests.push(args); return { ok: true }; };
const baseline = { recipe_revision: 4, current_version_id: 12, costing_revision: 0, status: 'available', release_url: '/release' };
let finishAcquisition;
let beginCalls = 0;
let changed = false;
const workbench = { formulaName: 'Local draft', t: key => key, isFormulaLocked: false, $wire: {
    async beginEditing() {
        beginCalls++;
        if (beginCalls === 1) { await new Promise(resolve => { finishAcquisition = resolve; }); }
        return { ok: true, editing: { ...baseline, status: 'acquired' } };
    },
    async editingStatus() { return { ok: true, editing: { ...baseline, recipe_revision: changed ? 5 : 4 } }; },
    async releaseEditing() { return { ok: true }; },
} };
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(createEditingSection({ canPersist: true, recipe: { id: 1 }, editing: baseline })));
const acquisition = workbench.startEditingProtection();
window.dispatchEvent(new Event('pagehide'));
finishAcquisition();
await acquisition;
assert.ok(requests.length >= 1, 'A reservation acquired after departure still needs release');
assert.equal(workbench.canWriteRecipe, false);
const pageshow = () => {
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: true });
    window.dispatchEvent(event);
};
pageshow();
await tick();
assert.equal(workbench.canWriteRecipe, true);
assert.equal(beginCalls, 2);
assert.equal(workbench.formulaName, 'Local draft');
window.dispatchEvent(new Event('pagehide'));
changed = true;
pageshow();
await tick();
assert.equal(workbench.editingStale, true, 'Browser restoration cannot overwrite a newer saved formula');
assert.equal(workbench.canWriteRecipe, false);
assert.equal(beginCalls, 2);
assert.equal(workbench.editingRecipeRevision, 4);
assert.equal(workbench.formulaName, 'Local draft');
workbench.destroyEditingProtection();
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('does not renew or restart queued work after departure and contains release failures', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible', hasFocus: () => true, querySelector: () => null });
globalThis.window = new EventTarget();
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};
let releases = 0;
globalThis.fetch = async () => { releases++; throw new Error('Offline'); };
const baseline = { recipe_revision: 4, current_version_id: 12, costing_revision: 0, release_url: '/release' };
let finishSave;
let heartbeats = 0;
let beginCalls = 0;
let blocked = false;
const workbench = { t: key => key, isFormulaLocked: false, $wire: {
    async beginEditing() { beginCalls++; return { ok: true, editing: { ...baseline, status: blocked ? 'blocked' : 'acquired' } }; },
    async heartbeatEditing() { heartbeats++; throw new Error('Departed pages cannot renew'); },
    async editingStatus() { return { ok: true, editing: { ...baseline, status: 'available' } }; },
} };
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(createEditingSection({ canPersist: true, recipe: { id: 1 }, editing: baseline })));
await workbench.startEditingProtection();
const save = workbench.queueRevisionMutation(async () => {
    await new Promise(resolve => { finishSave = resolve; });
    return { ok: true, editing: { ...baseline, recipe_revision: 5, status: 'acquired' } };
});
await new Promise(resolve => setImmediate(resolve));
const poll = workbench.pollEditingState();
window.dispatchEvent(new Event('pagehide'));
finishSave();
await save;
await poll;
assert.equal(heartbeats, 0, 'A poll queued behind a save must stop on departure');
assert.equal(workbench.canWriteRecipe, false);
assert.equal(beginCalls, 1);
assert.equal(releases, 2, 'A late acquired response must be released after the initial departure request');
workbench.destroyEditingProtection();

blocked = true;
const observer = { ...workbench, $wire: workbench.$wire };
Object.defineProperties(observer, Object.getOwnPropertyDescriptors(createEditingSection({ canPersist: true, recipe: { id: 1 }, editing: baseline })));
await observer.startEditingProtection();
assert.equal(observer.editingStatus, 'blocked');
window.dispatchEvent(new Event('pagehide'));
const restored = new Event('pageshow');
Object.defineProperty(restored, 'persisted', { value: true });
window.dispatchEvent(restored);
await new Promise(resolve => setImmediate(resolve));
assert.equal(observer.editingStatus, 'available');
assert.equal(observer.canWriteRecipe, false, 'Restoring a waiting page still requires Resume editing');
assert.equal(beginCalls, 2);
observer.destroyEditingProtection();
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('starts editing protection when newer input keeps the first save on the creation page', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
import { draftSignature } from './resources/js/recipe-workbench/draft-signature.js';
let focused = true;
let expired = false;
globalThis.document = { visibilityState: 'visible', hasFocus: () => focused, addEventListener() {}, removeEventListener() {} };
globalThis.window = { addEventListener() {}, removeEventListener() {}, location: { assign() { throw new Error('Newer input must stay on the page'); } } };
let poll;
globalThis.setInterval = callback => { poll = callback; return 1; };
globalThis.clearInterval = () => {};
const serializeDraft = workbench => ({ name: workbench.formulaName });
const bridge = fs.readFileSync('resources/js/recipe-workbench/bridge.js', 'utf8').replace(/^import[^;]+;\n/gm, '').replaceAll('export async function', 'async function');
eval(`${bridge}\nglobalThis.persistWorkbench = persistWorkbench;`);
const persistWorkbench = globalThis.persistWorkbench;
const source = fs.readFileSync('resources/js/recipe-workbench/component.js', 'utf8').replace(/^import[\s\S]*?;\n/gm, '').replaceAll('export function', 'function');
eval(`${source}\nglobalThis.createPersistenceSection = createPersistenceSection;`);
const baseline = { recipe_revision: 0, current_version_id: 12, costing_revision: 0, status: 'available' };
let beginCalls = 0;
let heartbeatCalls = 0;
const workbench = {
    recipeId: null, formulaName: 'Submitted', isFormulaLocked: false, costingSaveSeq: 0,
    t: key => key, flushCostingSave: async () => true, refreshDirtyBaseline() {},
    dirtyStateRegistry: { set() {} },
    $wire: {
        async save() {
            workbench.formulaName = 'Newer unsaved input';
            return { ok: true, editing: baseline, snapshot: { draft: { recipe: { id: 30 } } }, redirect: '/saved-formula' };
        },
        async beginEditing(token) {
            beginCalls++;
            assert.match(token, /^[0-9a-f-]{36}$/);
            return { ok: true, editing: { ...baseline, status: 'acquired' } };
        },
        async heartbeatEditing() {
            heartbeatCalls++;
            return expired ? { ok: false, errors: { editing_lease: ['Expired'] } } : { ok: true, editing: { ...baseline, status: 'acquired' } };
        },
        async editingStatus() { return { ok: true, editing: baseline }; },
        async releaseEditing() { return { ok: true }; },
    },
};
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(createEditingSection({ canPersist: true, recipe: null, editing: null })));
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPersistenceSection()));
await workbench.persist('save');
assert.equal(workbench.editingRequired, true);
assert.equal(workbench.recipeId, 30);
assert.equal(workbench.formulaName, 'Newer unsaved input');
assert.equal(workbench.editingStatus, 'acquired');
assert.equal(workbench.canWriteRecipe, true);
assert.equal(beginCalls, 1);
assert.equal(typeof poll, 'function');
await workbench.pollEditingState();
assert.equal(heartbeatCalls, 1);
focused = false;
await workbench.pollEditingState();
assert.equal(heartbeatCalls, 1);
focused = true;
expired = true;
await workbench.pollEditingState();
assert.equal(workbench.editingStatus, 'acquired');
assert.equal(workbench.canWriteRecipe, true);
assert.equal(workbench.formulaName, 'Newer unsaved input');
assert.equal(beginCalls, 2);
workbench.destroyEditingProtection();
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

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

it('restores only this pages expired reservation when the formula is unchanged and unclaimed', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createEditingSection } from './resources/js/recipe-workbench/editing.js';
let focused = true;
globalThis.document = { visibilityState: 'visible', hasFocus: () => focused, addEventListener() {}, removeEventListener() {} };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};
const baseline = { recipe_revision: 10, current_version_id: 31, costing_revision: 2 };

for (const scenario of ['expired', 'blocked', 'stale', 'race', 'acquire-stale', 'locked']) {
    let beginCalls = 0;
    let heartbeatCalls = 0;
    let releaseCalls = 0;
    const tokens = [];
    const workbench = createEditingSection({ canPersist: true, recipe: { id: 30 }, editing: baseline });
    Object.assign(workbench, {
        isFormulaLocked: false, formulaName: 'Local draft', t: key => key,
        $wire: {
            async beginEditing(token) {
                beginCalls++;
                tokens.push(token);
                const status = scenario === 'race' && beginCalls > 1 ? 'blocked' : 'acquired';
                return { ok: true, editing: {
                    ...baseline, status,
                    recipe_revision: scenario === 'acquire-stale' && beginCalls > 1 ? 11 : 10,
                } };
            },
            async heartbeatEditing() {
                heartbeatCalls++;
                return { ok: false, errors: { editing_lease: ['Expired'] } };
            },
            async editingStatus() {
                return { ok: true, editing: {
                    ...baseline,
                    recipe_revision: scenario === 'stale' ? 11 : 10,
                    status: scenario === 'blocked' ? 'blocked' : 'available',
                } };
            },
            async releaseEditing() { releaseCalls++; return { ok: true }; },
        },
    });
    await workbench.startEditingProtection();
    const token = workbench.editingToken;
    workbench.isFormulaLocked = scenario === 'locked';
    focused = false;
    await workbench.pollEditingState();
    assert.equal(heartbeatCalls, 0, 'Background pages must not keep the reservation alive');
    focused = true;
    await workbench.pollEditingState();

    assert.equal(workbench.formulaName, 'Local draft', 'Recovery must preserve local input');
    assert.equal(workbench.editingRecipeRevision, 10, 'Polling must not rebase the draft');
    assert.equal(releaseCalls, scenario === 'acquire-stale' ? 1 : 0, 'Release only a reservation acquired against a stale draft');
    assert.deepEqual(tokens, ['expired', 'race', 'acquire-stale'].includes(scenario) ? [token, token] : [token]);
    assert.equal(workbench.editingStatus, {
        expired: 'acquired', blocked: 'blocked', stale: 'stale', race: 'blocked', 'acquire-stale': 'stale', locked: 'available',
    }[scenario]);
    assert.equal(workbench.canWriteRecipe, scenario === 'expired');
    workbench.destroyEditingProtection();
}
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
