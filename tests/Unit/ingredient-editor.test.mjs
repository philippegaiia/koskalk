import assert from 'node:assert/strict';
import test from 'node:test';

import { createDirtyStateRegistry } from '../../resources/js/dirty-state-registry.js';
import { createIngredientEditor, stableSerialize } from '../../resources/js/ingredient-editor.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(name, callback, options = false) {
        const listeners = this.listeners.get(name) ?? [];
        listeners.push({ callback, capture: options === true || options?.capture === true });
        this.listeners.set(name, listeners);
    }

    removeEventListener(name, callback, options = false) {
        const capture = options === true || options?.capture === true;
        const listeners = this.listeners.get(name) ?? [];
        this.listeners.set(name, listeners.filter((listener) => listener.callback !== callback || listener.capture !== capture));
    }

    dispatch(name, event = {}) {
        const listeners = [...(this.listeners.get(name) ?? [])]
            .sort((left, right) => Number(right.capture) - Number(left.capture));

        const dispatchedEvent = {
            type: name,
            target: event.target ?? null,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopImmediatePropagation() {
                this.immediatePropagationStopped = true;
            },
            ...event,
        };

        for (const listener of listeners) {
            listener.callback(dispatchedEvent);

            if (dispatchedEvent.immediatePropagationStopped) {
                break;
            }
        }

        return dispatchedEvent;
    }

    listenerCount(name = null) {
        if (name !== null) {
            return (this.listeners.get(name) ?? []).length;
        }

        return [...this.listeners.values()].reduce((total, listeners) => total + listeners.length, 0);
    }
}

class FakeElement {
    constructor(scope, action = null) {
        this.dataset = {
            ingredientScope: scope,
            ingredientGuidanceReplace: action,
        };
        this.parentElement = null;
    }

    closest(selector) {
        if (selector === '[data-ingredient-scope]') {
            return this;
        }

        if (selector === '[data-ingredient-guidance-replace]') {
            return this.dataset.ingredientGuidanceReplace === null ? null : this;
        }

        return null;
    }
}

class FakeWire {
    constructor(state) {
        this.state = state;
        this.watchers = new Map();
        this.listeners = new Map();
        this.hooks = new Map();
        this.pendingCommit = null;
    }

    $watch(path, callback) {
        const watchers = this.watchers.get(path) ?? [];
        watchers.push(callback);
        this.watchers.set(path, watchers);

        return () => {
            this.watchers.set(path, (this.watchers.get(path) ?? []).filter((watcher) => watcher !== callback));
        };
    }

    $on(name, callback) {
        const listeners = this.listeners.get(name) ?? [];
        listeners.push(callback);
        this.listeners.set(name, listeners);

        return () => {
            this.listeners.set(name, (this.listeners.get(name) ?? []).filter((listener) => listener !== callback));
        };
    }

    $hook(name, callback) {
        this.hooks.set(name, callback);

        return () => this.hooks.delete(name);
    }

    get(path) {
        return path.split('.').reduce((value, key) => value?.[key], this.state);
    }

    set(path, value) {
        const segments = path.split('.');
        const finalKey = segments.pop();
        const target = segments.reduce((copy, key) => copy[key], this.state);
        target[finalKey] = value;

        for (const [watchedPath, callbacks] of this.watchers.entries()) {
            if (watchedPath !== path && !path.startsWith(`${watchedPath}.`)) {
                continue;
            }

            for (const watcher of callbacks) {
                watcher(this.get(watchedPath));
            }
        }
    }

    emit(name, detail = {}) {
        for (const listener of this.listeners.get(name) ?? []) {
            listener(detail);
        }
    }

    startCommit() {
        const succeed = [];
        const fail = [];
        const hook = this.hooks.get('commit');

        hook?.({
            succeed(callback) {
                succeed.push(callback);
            },
            fail(callback) {
                fail.push(callback);
            },
        });

        this.pendingCommit = { succeed, fail };
    }

    completeCommit(effects = {}) {
        const commit = this.pendingCommit;
        this.pendingCommit = null;

        for (const callback of commit?.succeed ?? []) {
            callback({ effects });
        }
    }

    failCommit(error = new Error('Network failure')) {
        const commit = this.pendingCommit;
        this.pendingCommit = null;

        for (const callback of commit?.fail ?? []) {
            callback(error);
        }
    }
}

function makeEditor(overrides = {}) {
    const state = {
        data: {
            name: 'Argan oil',
            components: [{ ingredient_id: 1, percentage: 60 }],
            media: [{ id: 10, role: 'featured' }],
            description: { type: 'doc', content: [{ type: 'paragraph', content: [{ text: 'Useful.' }] }] },
        },
        workspaceGuidance: { html: '<p>Use in the oil phase.</p>' },
        workspaceMaterialCode: 'ARGAN-01',
    };
    const wire = new FakeWire(state);
    const eventTarget = new FakeEventTarget();
    const windowTarget = new FakeEventTarget();
    const navigationTarget = new FakeEventTarget();
    const confirmations = [];
    const registry = createDirtyStateRegistry();

    const editor = createIngredientEditor({
        registry,
        wire,
        eventTarget,
        windowTarget,
        navigationTarget,
        baselines: {
            ingredient: structuredClone(state.data),
            guidance: structuredClone(state.workspaceGuidance),
            'material-code': state.workspaceMaterialCode,
        },
        editable: {
            ingredient: true,
            guidance: true,
            'material-code': true,
        },
        confirm(message) {
            confirmations.push(message);

            return true;
        },
        ...overrides,
    });

    return { editor, state, wire, eventTarget, windowTarget, navigationTarget, confirmations, registry };
}

function edit(wire, path, value) {
    wire.set(path, value);
}

function submit(target, editor, wire) {
    target.dispatch('submit', { target: new FakeElement(editor.scopeForElement(target) ?? 'ingredient') });
    wire.startCommit();
}

test('keeps another dirty scope blocked when one scope saves', () => {
    const { editor, wire, eventTarget, registry } = makeEditor();

    editor.init();
    edit(wire, 'data.name', 'Updated argan oil');
    edit(wire, 'workspaceGuidance.html', '<p>Use cold processing.</p>');

    eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    wire.startCommit();
    wire.emit('ingredient-editor:saved', { scope: 'ingredient' });
    wire.completeCommit();

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(editor.stateFor('guidance'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
});

test('returns a scope to clean after editing back to its stable baseline', () => {
    const { editor, wire, registry } = makeEditor();

    editor.init();
    edit(wire, 'data', {
        description: { content: [{ type: 'paragraph', content: [{ text: 'Useful.' }] }], type: 'doc' },
        media: [{ role: 'featured', id: 10 }],
        components: [{ percentage: 60, ingredient_id: 1 }],
        name: 'Argan oil',
    });

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(registry.blocksNavigation(), false);
});

test('keeps validation and network failures blocking after a submit', async () => {
    const validation = makeEditor();
    validation.editor.init();
    edit(validation.wire, 'data.name', 'Invalid');
    validation.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    validation.wire.startCommit();
    validation.wire.completeCommit({ errors: { 'data.name': ['Invalid name'] } });
    await new Promise((resolve) => queueMicrotask(resolve));

    assert.equal(validation.editor.stateFor('ingredient'), 'failed');
    assert.equal(validation.registry.blocksNavigation(), true);

    const network = makeEditor();
    network.editor.init();
    edit(network.wire, 'data.name', 'Offline');
    network.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    network.wire.startCommit();
    network.wire.failCommit();

    assert.equal(network.editor.stateFor('ingredient'), 'failed');
    assert.equal(network.registry.blocksNavigation(), true);
});

test('keeps edits made during an in-flight save dirty after persistence succeeds', () => {
    const { editor, wire, eventTarget, registry } = makeEditor();

    editor.init();
    edit(wire, 'data.name', 'Saved name');
    eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    wire.startCommit();
    edit(wire, 'data.name', 'Newer name');
    wire.emit('ingredient-editor:saved', { scope: 'ingredient' });
    wire.completeCommit();

    assert.equal(editor.stateFor('ingredient'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
    assert.equal(editor.baselineFor('ingredient').name, 'Saved name');
});

test('does not make read-only scopes dirty', () => {
    const { editor, wire, registry } = makeEditor({
        editable: {
            ingredient: false,
            guidance: false,
            'material-code': false,
        },
    });

    editor.init();
    edit(wire, 'data.name', 'Ignored');
    edit(wire, 'workspaceGuidance.html', '<p>Ignored.</p>');
    edit(wire, 'workspaceMaterialCode', 'IGNORED');

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(editor.stateFor('guidance'), 'saved');
    assert.equal(editor.stateFor('material-code'), 'saved');
    assert.equal(registry.blocksNavigation(), false);
});

test('uses input and change fallback events for buffered fields and rich editors', () => {
    const { editor, eventTarget, registry } = makeEditor();

    editor.init();
    eventTarget.dispatch('input', { target: new FakeElement('guidance') });

    assert.equal(editor.stateFor('guidance'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
});

test('captures buffered state after submit has flushed before marking it saved', () => {
    const { editor, state, wire, eventTarget } = makeEditor();

    editor.init();
    state.data.name = 'Buffered before blur';
    eventTarget.dispatch('input', { target: new FakeElement('ingredient') });
    eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    wire.startCommit();
    wire.emit('ingredient-editor:saved', { scope: 'ingredient' });
    wire.completeCommit();

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(editor.baselineFor('ingredient').name, 'Buffered before blur');
});

test('installs one navigation guard and removes every listener on destroy', () => {
    const { editor, eventTarget, windowTarget, navigationTarget, registry } = makeEditor();

    editor.init();
    editor.init();

    assert.equal(eventTarget.listenerCount('input'), 1);
    assert.equal(eventTarget.listenerCount('change'), 1);
    assert.equal(eventTarget.listenerCount('submit'), 1);
    assert.equal(windowTarget.listenerCount('beforeunload'), 1);
    assert.equal(navigationTarget.listenerCount('livewire:navigate'), 1);

    const beforeUnload = windowTarget.dispatch('beforeunload');
    assert.equal(beforeUnload.defaultPrevented, false);

    editor.markDirty('ingredient');
    const blockedUnload = windowTarget.dispatch('beforeunload');
    assert.equal(blockedUnload.defaultPrevented, true);

    const navigation = navigationTarget.dispatch('livewire:navigate');
    assert.equal(navigation.defaultPrevented, false);

    editor.destroy();
    editor.destroy();

    assert.equal(eventTarget.listenerCount(), 0);
    assert.equal(windowTarget.listenerCount(), 0);
    assert.equal(navigationTarget.listenerCount(), 0);
    assert.equal(registry.blocksNavigation(), false);
});

test('confirms replacing an unsaved guidance draft and starts only that save', () => {
    const { editor, eventTarget, wire, confirmations, registry } = makeEditor();

    editor.init();
    editor.markDirty('ingredient');
    editor.markDirty('guidance');
    const button = new FakeElement('guidance', 'usePlatformGuidance');

    eventTarget.dispatch('click', { target: button });

    assert.equal(confirmations.length, 1);
    assert.match(confirmations[0], /unsaved/i);
    assert.equal(editor.stateFor('guidance'), 'saving');
    assert.equal(editor.stateFor('ingredient'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
    assert.equal(wire.listeners.get('ingredient-editor:saved').length, 1);
});

test('serializes nested values with stable object-key order', () => {
    const left = { rich: { type: 'doc', content: [{ attrs: { level: 2, align: null } }] }, media: [{ id: 1, role: 'featured' }] };
    const right = { media: [{ role: 'featured', id: 1 }], rich: { content: [{ attrs: { align: null, level: 2 } }], type: 'doc' } };

    assert.equal(stableSerialize(left), stableSerialize(right));
});
