<?php

use Symfony\Component\Process\Process;

it('fixes the save deadline at the first dirty event', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(1_000);
const controller = createRecipeContentAutosave({ clock });

controller.markDirty();

assert.equal(controller.state, 'dirty');
assert.equal(controller.dirtySince, 1_000);
assert.equal(controller.saveDeadline, 121_000);

clock.advance(45_000);
controller.markDirty();

assert.equal(controller.dirtySince, 1_000);
assert.equal(controller.saveDeadline, 121_000);
JS);
});

it('starts a fresh interval after a successful save', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(5_000);
const registry = createDirtyStateRegistry();
const controller = createRecipeContentAutosave({
    clock,
    registry,
    save: async () => ({ ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' }),
});

controller.markDirty();
await controller.save();

assert.equal(controller.state, 'saved');
assert.equal(controller.dirtySince, null);
assert.equal(controller.saveDeadline, null);
assert.equal(registry.blocksNavigation(), false);

clock.advance(10_000);
controller.markDirty();

assert.equal(controller.dirtySince, 15_000);
assert.equal(controller.saveDeadline, 135_000);
JS);
});

it('waits for active uploads before an overdue save', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(10_000);
let saves = 0;
const controller = createRecipeContentAutosave({
    clock,
    save: async () => {
        saves += 1;

        return { ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' };
    },
});

controller.markDirty();
controller.uploadStarted();
clock.advance(120_000);
await settle();

assert.equal(saves, 0);
assert.equal(controller.state, 'dirty');
assert.equal(controller.activeUploads, 1);

controller.uploadFinished();
await settle();

assert.equal(saves, 1);
assert.equal(controller.state, 'saved');
assert.equal(controller.activeUploads, 0);
JS);
});

it('shares one in-flight promise between automatic and manual saves', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const deferredSave = deferred();
let saves = 0;
const controller = createRecipeContentAutosave({
    clock,
    save: () => {
        saves += 1;

        return deferredSave.promise;
    },
});

controller.markDirty();
clock.advance(120_000);

const automaticSave = controller.inFlight;
const manualSave = controller.save();

assert.ok(automaticSave instanceof Promise);
assert.strictEqual(manualSave, automaticSave);
assert.equal(saves, 1);

deferredSave.resolve({ ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' });
await manualSave;

assert.equal(controller.state, 'saved');
assert.equal(saves, 1);
JS);
});

it('keeps failures blocking until a later successful save', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const registry = createDirtyStateRegistry();
let attempt = 0;
const controller = createRecipeContentAutosave({
    registry,
    save: async () => {
        attempt += 1;

        return attempt === 1
            ? { ok: false, message: 'Could not save' }
            : { ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' };
    },
});

controller.markDirty();
await controller.save();

assert.equal(controller.state, 'failed');
assert.equal(registry.blocksNavigation(), true);

await controller.save();

assert.equal(controller.state, 'saved');
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('blocks navigation only for dirty saving and failed registry entries', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const registry = createDirtyStateRegistry();

registry.set('recipe-content', 'saved');
assert.equal(registry.blocksNavigation(), false);

for (const state of ['dirty', 'saving', 'failed']) {
    registry.set('recipe-content', state);
    assert.equal(registry.blocksNavigation(), true, `${state} should block navigation`);
}

registry.set('recipe-content', 'saved');
assert.equal(registry.blocksNavigation(), false);

registry.set('recipe-content', 'dirty');
registry.remove('recipe-content');
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('attaches scoped listeners once and destroys them safely', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const eventTarget = new FakeEventTarget();
const registry = createDirtyStateRegistry();
const controller = createRecipeContentAutosave({ clock, eventTarget, registry });

controller.init();
controller.init();

for (const eventName of ['input', 'change', 'livewire-upload-start', 'livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
    assert.equal(eventTarget.listenerCount(eventName), 1, `${eventName} should be attached once`);
}

assert.equal(eventTarget.captureFor('input'), true);
assert.equal(eventTarget.captureFor('change'), true);
assert.equal(eventTarget.captureFor('livewire-upload-start'), false);

eventTarget.dispatch('input');
assert.equal(controller.state, 'dirty');
assert.equal(clock.timers.size, 1);

controller.destroy();
controller.destroy();

assert.equal(eventTarget.listenerCount(), 0);
assert.equal(clock.timers.size, 0);
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('watches active instruction and featured media state paths without stale subscriptions', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const watchSource = new FakeWatchSource({
    'data.description': { type: 'doc', content: [] },
    'data.manufacturing_instructions': { type: 'doc', content: [] },
    'data.featured_media_asset_id': null,
});
const controller = createRecipeContentAutosave({
    clock,
    watch: watchSource.watch,
});

controller.init();
controller.init();

assert.equal(controller.state, 'saved');
assert.equal(controller.dirtySince, null);
assert.equal(clock.timers.size, 0);
assert.equal(watchSource.listenerCount('data.description'), 1);
assert.equal(watchSource.listenerCount('data.manufacturing_instructions'), 1);
assert.equal(watchSource.listenerCount('data.featured_media_asset_id'), 1);
assert.equal(watchSource.listenerCount('data.manufacturing_media_asset_ids'), 0);

watchSource.set('data.featured_media_asset_id', 123);

assert.equal(controller.state, 'dirty');
assert.equal(controller.dirtySince, 0);
assert.equal(clock.timers.size, 1);

controller.destroy();
controller.destroy();

assert.equal(watchSource.listenerCount(), 0);
assert.equal(watchSource.unsubscribeCalls, 3);

watchSource.set('data.manufacturing_media_asset_ids', [123, 456]);
assert.equal(clock.timers.size, 0);
JS);
});

it('tracks concurrent Filament rich editor uploads without counting form processing twice', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const form = new FakeEventTarget();
const windowTarget = new FakeEventTarget();
let saves = 0;
const controller = createRecipeContentAutosave({
    clock,
    eventTarget: form,
    uploadEventTarget: windowTarget,
    livewireId: 'recipe-workbench-1',
    save: async () => {
        saves += 1;

        return { ok: true, saved_at: '2026-07-22T10:15:00.000000Z' };
    },
});

controller.init();

form.dispatch('form-processing-started');
windowTarget.dispatch('rich-editor-uploading-file', { livewireId: 'another-component', key: 'description' });
windowTarget.dispatch('rich-editor-uploading-file', { livewireId: 'recipe-workbench-1', key: 'description' });
windowTarget.dispatch('rich-editor-uploading-file', { livewireId: 'recipe-workbench-1', key: 'manufacturing-instructions' });

assert.equal(controller.activeUploads, 2);
assert.equal(controller.state, 'dirty');

clock.advance(120_000);
await settle();
assert.equal(saves, 0);

windowTarget.dispatch('rich-editor-uploaded-file', { livewireId: 'recipe-workbench-1', key: 'description' });
form.dispatch('form-processing-finished');
assert.equal(controller.activeUploads, 1);
assert.equal(saves, 0);

windowTarget.dispatch('rich-editor-uploaded-file', { livewireId: 'recipe-workbench-1', key: 'manufacturing-instructions' });
await settle();

assert.equal(controller.activeUploads, 0);
assert.equal(saves, 1);
assert.equal(controller.state, 'saved');
JS);
});

it('keeps the browser upload target outside Alpine reactive state', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const windowTarget = new FakeEventTarget();
windowTarget.window = windowTarget;

const controller = createRecipeContentAutosave({ uploadEventTarget: windowTarget });

assert.equal(
    Object.prototype.propertyIsEnumerable.call(controller, 'uploadEventTarget'),
    false,
);
JS);
});

it('uses Filament and Livewire terminal events to prevent stuck upload state', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const form = new FakeEventTarget();
const windowTarget = new FakeEventTarget();
const controller = createRecipeContentAutosave({
    clock: new FakeClock(0),
    eventTarget: form,
    uploadEventTarget: windowTarget,
    livewireId: 'recipe-workbench-1',
});

controller.init();

form.dispatch('form-processing-started');
windowTarget.dispatch('rich-editor-uploading-file', { livewireId: 'recipe-workbench-1', key: 'description' });
windowTarget.dispatch('rich-editor-uploaded-file', { livewireId: 'recipe-workbench-1', key: 'description' });
form.dispatch('form-processing-finished');
assert.equal(controller.activeUploads, 0);

form.dispatch('livewire-upload-start');
form.dispatch('livewire-upload-start');
form.dispatch('livewire-upload-error');
form.dispatch('livewire-upload-cancel');
assert.equal(controller.activeUploads, 0);
assert.equal(controller.state, 'failed');

windowTarget.dispatch('rich-editor-file-validation-message', {
    livewireId: 'recipe-workbench-1',
    key: 'description',
    validationMessage: 'Invalid file',
});
assert.equal(controller.activeUploads, 0);

controller.destroy();

for (const eventName of ['rich-editor-uploading-file', 'rich-editor-uploaded-file', 'rich-editor-file-validation-message']) {
    assert.equal(windowTarget.listenerCount(eventName), 0, `${eventName} should be removed`);
}

for (const eventName of ['form-processing-started', 'form-processing-finished']) {
    assert.equal(form.listenerCount(eventName), 0, `${eventName} should be removed`);
}
JS);
});

it('fails safely when Filament emits no terminal event for a rich editor upload', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const form = new FakeEventTarget();
const windowTarget = new FakeEventTarget();
const registry = createDirtyStateRegistry();
const controller = createRecipeContentAutosave({
    clock,
    eventTarget: form,
    uploadEventTarget: windowTarget,
    livewireId: 'recipe-workbench-1',
    registry,
    uploadSafetyInterval: 30_000,
});

controller.init();
form.dispatch('form-processing-started');
windowTarget.dispatch('rich-editor-uploading-file', { livewireId: 'recipe-workbench-1', key: 'description' });

clock.advance(30_000);

assert.equal(controller.activeUploads, 0);
assert.equal(controller.state, 'failed');
assert.equal(registry.blocksNavigation(), true);
assert.equal(clock.timers.size, 0);
JS);
});

it('ignores a resolved in-flight save after destruction', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const registry = createDirtyStateRegistry();
const pendingSave = deferred();
const controller = createRecipeContentAutosave({ clock, registry, save: () => pendingSave.promise });

controller.init();
controller.markDirty();
const save = controller.save();
controller.markDirty();
controller.destroy();

pendingSave.resolve({ ok: true, saved_at: '2026-07-22T10:15:00.000000Z' });
await save;

assert.equal(controller.state, 'saving');
assert.equal(controller.inFlight, null);
assert.equal(clock.timers.size, 0);
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('ignores a rejected in-flight save after destruction', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const registry = createDirtyStateRegistry();
const pendingSave = deferred();
const controller = createRecipeContentAutosave({ clock, registry, save: () => pendingSave.promise });

controller.init();
controller.markDirty();
const save = controller.save();
controller.destroy();

pendingSave.reject(new Error('Network failed'));
await save;

assert.equal(controller.state, 'saving');
assert.equal(controller.errorMessage, '');
assert.equal(controller.inFlight, null);
assert.equal(clock.timers.size, 0);
assert.equal(registry.blocksNavigation(), false);
JS);
});

it('documents the installed Filament rich editor state and upload event contract', function () {
    $richEditorSource = file_get_contents(dirname(__DIR__, 2).'/vendor/filament/forms/resources/js/components/rich-editor.js');
    $localFilesSource = file_get_contents(dirname(__DIR__, 2).'/vendor/filament/forms/resources/js/components/rich-editor/extension-local-files.js');

    expect($richEditorSource)
        ->toContain("editor.on('update'")
        ->toContain('this.state = editor.getJSON()')
        ->toContain("'rich-editor-uploading-file'")
        ->toContain("'rich-editor-uploaded-file'")
        ->toContain("'rich-editor-file-validation-message'");

    expect($localFilesSource)
        ->toContain("'form-processing-started'")
        ->toContain("'form-processing-finished'");
});

it('formats saved timestamps with the browser locale', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const isoTimestamp = '2026-07-22T10:15:00.000000Z';
const controller = createRecipeContentAutosave({
    clock: new FakeClock(0),
    save: async () => ({ ok: true, message: 'Saved', saved_at: isoTimestamp }),
});

controller.markDirty();
await controller.save();

assert.ok(controller.savedAt.length > 0);
assert.notEqual(controller.savedAt, isoTimestamp);
assert.match(controller.savedAt, /\d{1,2}[^\d]\d{2}/);
assert.equal(controller.statusText, `Saved at ${controller.savedAt}`);
JS);
});

it('makes upload errors failed and blocking while decrementing uploads', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const registry = createDirtyStateRegistry();
const controller = createRecipeContentAutosave({ registry, clock: new FakeClock(0) });

controller.markDirty();
controller.uploadStarted();
controller.uploadStarted();
controller.uploadErrored();

assert.equal(controller.activeUploads, 1);
assert.equal(controller.state, 'failed');
assert.equal(registry.blocksNavigation(), true);

controller.uploadErrored();

assert.equal(controller.activeUploads, 0);
assert.equal(controller.state, 'failed');
assert.equal(registry.blocksNavigation(), true);
JS);
});

it('saves overdue dirty content after the final upload is cancelled', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
let saves = 0;
const controller = createRecipeContentAutosave({
    clock,
    save: async () => {
        saves += 1;

        return { ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' };
    },
});

controller.markDirty();
controller.uploadStarted();
clock.advance(120_000);
controller.uploadCancelled();
await settle();

assert.equal(controller.activeUploads, 0);
assert.equal(saves, 1);
assert.equal(controller.state, 'saved');
JS);
});

it('keeps edits made during a save dirty until a fixed-deadline follow-up succeeds', function () {
    runRecipeContentAutosaveContract(<<<'JS'
const clock = new FakeClock(0);
const firstSave = deferred();
let saves = 0;
const controller = createRecipeContentAutosave({
    clock,
    save: () => {
        saves += 1;

        return saves === 1
            ? firstSave.promise
            : Promise.resolve({ ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' });
    },
});

controller.markDirty();
clock.advance(100_000);
const inFlight = controller.save();
clock.advance(10_000);
controller.markDirty();

firstSave.resolve({ ok: true, message: 'Saved', saved_at: '2026-07-22T10:15:00.000000Z' });
await inFlight;

assert.equal(controller.state, 'dirty');
assert.equal(controller.saveDeadline, 120_000);
assert.equal(clock.nextDueAt(), 120_000);

clock.advance(10_000);
await settle();

assert.equal(saves, 2);
assert.equal(controller.state, 'saved');
JS);
});

it('lets Alpine own root initialization while preserving the public calculator adjustment', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/dashboard/recipe-workbench.blade.php');

    expect($source)
        ->not->toContain('x-init="init();')
        ->toContain('x-init="if (@js($isPublicCalculator)');
});

it('bridges the autosave controller to the public Livewire watcher and component id APIs', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php');

    expect($source)
        ->toContain('livewireId: $wire.$id')
        ->toContain('uploadEventTarget: window')
        ->toContain('watch: (path, callback) => $wire.$watch(path, callback)');
});

function runRecipeContentAutosaveContract(string $contract): void
{
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createDirtyStateRegistry } from './resources/js/dirty-state-registry.js';
import { createRecipeContentAutosave } from './resources/js/recipe-content-autosave.js';

class FakeClock {
    constructor(now) {
        this.currentTime = now;
        this.nextTimerId = 1;
        this.timers = new Map();
    }

    now = () => this.currentTime;

    setTimeout = (callback, delay) => {
        const timerId = this.nextTimerId++;
        this.timers.set(timerId, {
            callback,
            dueAt: this.currentTime + Math.max(0, delay),
        });

        return timerId;
    };

    clearTimeout = (timerId) => {
        this.timers.delete(timerId);
    };

    advance(milliseconds) {
        this.currentTime += milliseconds;

        while (true) {
            const dueTimer = [...this.timers.entries()]
                .filter(([, timer]) => timer.dueAt <= this.currentTime)
                .sort((left, right) => left[1].dueAt - right[1].dueAt)[0];

            if (!dueTimer) {
                return;
            }

            const [timerId, timer] = dueTimer;
            this.timers.delete(timerId);
            timer.callback();
        }
    }

    nextDueAt() {
        return [...this.timers.values()]
            .map((timer) => timer.dueAt)
            .sort((left, right) => left - right)[0] ?? null;
    }
}

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(eventName, callback, options = false) {
        const listeners = this.listeners.get(eventName) ?? [];
        listeners.push({ callback, capture: options === true || options?.capture === true });
        this.listeners.set(eventName, listeners);
    }

    removeEventListener(eventName, callback, options = false) {
        const capture = options === true || options?.capture === true;
        const listeners = this.listeners.get(eventName) ?? [];
        this.listeners.set(eventName, listeners.filter((listener) => listener.callback !== callback || listener.capture !== capture));
    }

    dispatch(eventName, detail = {}) {
        for (const listener of this.listeners.get(eventName) ?? []) {
            listener.callback({ detail, type: eventName });
        }
    }

    listenerCount(eventName = null) {
        if (eventName !== null) {
            return (this.listeners.get(eventName) ?? []).length;
        }

        return [...this.listeners.values()].reduce((total, listeners) => total + listeners.length, 0);
    }

    captureFor(eventName) {
        return (this.listeners.get(eventName) ?? [])[0]?.capture ?? null;
    }
}

class FakeWatchSource {
    constructor(values = {}) {
        this.listeners = new Map();
        this.unsubscribeCalls = 0;
        this.values = new Map(Object.entries(values));
    }

    watch = (path, callback) => {
        const listeners = this.listeners.get(path) ?? [];
        listeners.push(callback);
        this.listeners.set(path, listeners);

        let isSubscribed = true;

        return () => {
            if (!isSubscribed) {
                return;
            }

            isSubscribed = false;
            this.unsubscribeCalls += 1;
            this.listeners.set(path, (this.listeners.get(path) ?? []).filter((listener) => listener !== callback));
        };
    };

    set(path, value) {
        const previousValue = this.values.get(path);
        this.values.set(path, value);

        for (const listener of this.listeners.get(path) ?? []) {
            listener(value, previousValue);
        }
    }

    listenerCount(path = null) {
        if (path !== null) {
            return (this.listeners.get(path) ?? []).length;
        }

        return [...this.listeners.values()].reduce((total, listeners) => total + listeners.length, 0);
    }
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, reject, resolve };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$contract),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
}
