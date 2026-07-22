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

    dispatch(eventName) {
        for (const listener of this.listeners.get(eventName) ?? []) {
            listener.callback({ type: eventName });
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
