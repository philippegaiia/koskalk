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
