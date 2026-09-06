import assert from 'node:assert/strict';
import test from 'node:test';

import { createDirtyStateRegistry } from '../../resources/js/dirty-state-registry.js';
import {
    consumeIngredientEditorNotification,
    createIngredientEditor,
    stableSerialize,
} from '../../resources/js/ingredient-editor.js';

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

class FakeValidationControl {
    constructor(path) {
        this.path = path;
        this.focused = false;
        this.scrollOptions = null;
    }

    getAttributeNames() {
        return ['wire:model'];
    }

    getAttribute(name) {
        return {
            'wire:model': this.path,
        }[name] ?? null;
    }

    focus() {
        this.focused = true;
    }

    scrollIntoView(options) {
        this.scrollOptions = options;
    }
}

class FakeValidationRoot extends FakeEventTarget {
    constructor(tabButtons, controls) {
        super();
        this.tabButtons = tabButtons;
        this.controls = controls;
    }

    querySelector(selector) {
        const tabKey = selector.match(/\[data-tab-key="([^"]+)"\]/)?.[1];

        return this.tabButtons[tabKey] ?? null;
    }

    querySelectorAll() {
        return this.controls;
    }
}

class FakeTabButton {
    clicked = 0;

    click() {
        this.clicked += 1;
    }
}

class FakeStorage {
    constructor() {
        this.values = new Map();
    }

    getItem(key) {
        return this.values.get(key) ?? null;
    }

    setItem(key, value) {
        this.values.set(key, String(value));
    }

    removeItem(key) {
        this.values.delete(key);
    }
}

class FakeElement {
    constructor(scope, action = null, localCancel = false, confirmation = null) {
        this.dataset = {
            ingredientScope: scope,
            ingredientGuidanceReplace: action,
            ingredientGuidanceConfirm: confirmation,
            ingredientEditorLocalCancel: localCancel ? scope : null,
            ingredientEditorIgnoreDirty: null,
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

        if (selector === '[data-ingredient-editor-local-cancel]') {
            return this.dataset.ingredientEditorLocalCancel === null ? null : this;
        }

        if (selector === '[data-ingredient-editor-ignore-dirty]') {
            return this.dataset.ingredientEditorIgnoreDirty === null ? null : this;
        }

        return null;
    }
}

class FakeControl {
    constructor(tagName = 'input') {
        this.tagName = tagName.toUpperCase();
        this.disabled = false;
        this.readOnly = false;
        this.contentEditable = tagName === 'contenteditable' ? 'true' : 'false';
        this.attributes = new Map();

        if (tagName === 'contenteditable') {
            this.setAttribute('contenteditable', 'true');
        }
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));

        if (name === 'contenteditable') {
            this.contentEditable = String(value);
        }
    }

    removeAttribute(name) {
        this.attributes.delete(name);

        if (name === 'contenteditable') {
            this.contentEditable = 'inherit';
        }
    }
}

class FakeForm extends FakeElement {
    constructor(scope, controls = []) {
        super(scope);
        this.controls = controls;
    }

    querySelectorAll() {
        return this.controls;
    }
}

class FakeWire {
    constructor(state) {
        this.state = state;
        this.watchers = new Map();
        this.listeners = new Map();
        this.hooks = new Map();
        this.requestInterceptors = new Map();
        this.pendingCommits = [];
        this.redirectPrevented = false;
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

    $interceptRequest(method, callback) {
        const callbacks = this.requestInterceptors.get(method) ?? [];
        callbacks.push(callback);
        this.requestInterceptors.set(method, callbacks);

        return () => {
            this.requestInterceptors.set(
                method,
                (this.requestInterceptors.get(method) ?? []).filter((candidate) => candidate !== callback),
            );
        };
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

    startCommit(method = 'save', flush = null) {
        const succeed = [];
        const fail = [];
        const redirects = [];
        const hook = this.hooks.get('commit');

        hook?.({
            commit: { calls: [{ method }] },
            succeed(callback) {
                succeed.push(callback);
            },
            fail(callback) {
                fail.push(callback);
            },
        });

        flush?.();

        for (const callback of this.requestInterceptors.get(method) ?? []) {
            callback({
                request: { messages: [] },
                onRedirect(callback) {
                    redirects.push(callback);
                },
            });
        }

        this.pendingCommits.push({ succeed, fail, redirects });
    }

    completeCommit(effects = {}, index = 0, afterRedirect = null) {
        const [commit] = this.pendingCommits.splice(index, 1);

        this.redirectPrevented = false;
        if (effects.redirect) {
            let prevented = false;
            for (const callback of commit?.redirects ?? []) {
                callback({
                    url: effects.redirect,
                    preventDefault() {
                        prevented = true;
                    },
                });
            }
            this.redirectPrevented = prevented;
        }

        afterRedirect?.();

        for (const callback of commit?.succeed ?? []) {
            callback({ effects });
        }
    }

    failCommit(error = new Error('Network failure'), index = 0) {
        const [commit] = this.pendingCommits.splice(index, 1);

        for (const callback of commit?.fail ?? []) {
            callback(error);
        }
    }
}

function makeEditor(overrides = {}, stateOverrides = {}) {
    const state = {
        data: {
            name: 'Argan oil',
            components: [{ ingredient_id: 1, percentage: 60 }],
            media: [{ id: 10, role: 'featured' }],
            description: { type: 'doc', content: [{ type: 'paragraph', content: [{ text: 'Useful.' }] }] },
            ...(stateOverrides.data ?? {}),
        },
        workspaceGuidance: stateOverrides.workspaceGuidance ?? { html: '<p>Use in the oil phase.</p>' },
        workspaceMaterialCode: Object.prototype.hasOwnProperty.call(stateOverrides, 'workspaceMaterialCode')
            ? stateOverrides.workspaceMaterialCode
            : 'ARGAN-01',
    };
    const wire = new FakeWire(state);
    const eventTarget = new FakeEventTarget();
    const windowTarget = new FakeEventTarget();
    const navigationTarget = new FakeEventTarget();
    const confirmations = [];
    const registry = createDirtyStateRegistry();
    const confirmOverride = overrides.confirm;

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
        ...overrides,
        confirm(message) {
            confirmations.push(message);

            return typeof confirmOverride === 'function' ? confirmOverride(message) : true;
        },
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

test('reports pending changes for dirty, saving, and failed scopes', () => {
    const { editor } = makeEditor();

    for (const state of ['dirty', 'saving', 'failed']) {
        editor.scopeStates = {
            ingredient: state,
            guidance: 'saved',
            'material-code': 'saved',
        };

        assert.equal(editor.hasPendingChanges(), true);
    }

    editor.scopeStates = {
        ingredient: 'saved',
        guidance: 'saved',
        'material-code': 'saved',
    };

    assert.equal(editor.hasPendingChanges(), false);
});

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

test('treats an emptied material code as equivalent to a null baseline', () => {
    const setup = makeEditor({ confirm: () => false }, { workspaceMaterialCode: null });

    setup.editor.init();
    edit(setup.wire, 'workspaceMaterialCode', 'TMP-UNSAVED');
    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.registry.blocksNavigation(), true);

    edit(setup.wire, 'workspaceMaterialCode', '');

    assert.equal(setup.editor.stateFor('material-code'), 'saved');
    assert.equal(setup.registry.blocksNavigation(), false);

    const navigation = setup.navigationTarget.dispatch('livewire:navigate');
    assert.equal(navigation.defaultPrevented, false);
    assert.equal(setup.confirmations.length, 0);
});

test('keeps a dirty structure choice protected when the composition editor re-renders', () => {
    const setup = makeEditor({ isCreate: true, confirm: () => false });

    setup.editor.init();
    edit(setup.wire, 'data.ingredient_structure', 'blend');

    assert.equal(setup.editor.stateFor('ingredient'), 'dirty');
    assert.equal(setup.registry.blocksNavigation(), true);

    // A reactive Livewire response updates the complete data scope as it adds the composition UI.
    edit(setup.wire, 'data', {
        ...setup.state.data,
        ingredient_structure: 'blend',
        components: [],
    });

    assert.equal(setup.editor.stateFor('ingredient'), 'dirty');
    assert.equal(setup.registry.blocksNavigation(), true);

    const navigation = setup.navigationTarget.dispatch('livewire:navigate');
    assert.equal(navigation.defaultPrevented, true);
    assert.equal(setup.confirmations.length, 1);
});

test('describes a clean ingredient creation as not created without blocking navigation', () => {
    const setup = makeEditor({ isCreate: true, confirm: () => false });

    setup.editor.init();

    assert.equal(setup.editor.stateFor('ingredient'), 'saved');
    assert.equal(setup.editor.statusText('ingredient'), 'Not created yet');
    assert.equal(setup.registry.blocksNavigation(), false);

    edit(setup.wire, 'data.name', 'New ingredient');

    assert.equal(setup.editor.stateFor('ingredient'), 'dirty');
    assert.equal(setup.editor.statusText('ingredient'), 'Unsaved changes');
    assert.equal(setup.registry.blocksNavigation(), true);
});

test('uses server-provided labels for statuses and confirmation prompts', () => {
    const labels = {
        saved: 'Server saved label',
        dirty: 'Server dirty label',
        saving: 'Server saving label',
        failed: 'Server failed label',
        leaveWarning: 'Server leave warning',
        replaceGuidance: 'Server replace warning',
        cancelGuidance: 'Server cancel warning',
    };
    const setup = makeEditor({ labels, confirm: () => false });

    setup.editor.init();
    assert.equal(setup.editor.statusText('ingredient'), labels.saved);

    setup.editor.markDirty('ingredient');
    assert.equal(setup.editor.statusText('ingredient'), labels.dirty);

    setup.editor.beginSave('ingredient');
    assert.equal(setup.editor.statusText('ingredient'), labels.saving);

    setup.editor.failScope('ingredient');
    assert.equal(setup.editor.statusText('ingredient'), labels.failed);

    setup.editor.cancelScope('ingredient');
    setup.editor.markDirty('ingredient');
    setup.editor.markDirty('guidance');
    setup.eventTarget.dispatch('click', { target: new FakeElement('guidance', null, true) });
    setup.eventTarget.dispatch('click', { target: new FakeElement('guidance', 'usePlatformGuidance') });
    setup.navigationTarget.dispatch('livewire:navigate');

    assert.deepEqual(setup.confirmations, [
        labels.cancelGuidance,
        labels.replaceGuidance,
        labels.leaveWarning,
    ]);
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

test('activates the validation tab and focuses the first invalid field', async () => {
    const tabButton = new FakeTabButton();
    const control = new FakeValidationControl('data.name');
    const root = new FakeValidationRoot({ overview: tabButton }, [control]);
    const setup = makeEditor({ eventTarget: root });

    setup.editor.init();
    setup.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    setup.wire.startCommit();
    setup.wire.completeCommit({ errors: { 'data.name': ['The name field is required.'] } });

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(tabButton.clicked, 1);
    assert.equal(control.focused, true);
    assert.deepEqual(control.scrollOptions, { block: 'center', behavior: 'smooth' });
});

test('activates the correct tab for aggregate section validation errors', async () => {
    for (const [field, tabKey] of [
        ['data.components', 'composition'],
        ['data.fatty_acid_entries', 'soap-chemistry'],
        ['data.substance_entries', 'regulatory-data'],
        ['data.document_media_asset_ids', 'guidance-files'],
    ]) {
        const tabButton = new FakeTabButton();
        const control = new FakeValidationControl(field);
        const root = new FakeValidationRoot({ [tabKey]: tabButton }, [control]);
        const setup = makeEditor({ eventTarget: root });

        setup.editor.init();
        setup.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
        setup.wire.startCommit();
        setup.wire.completeCommit({ errors: { [field]: ['Invalid section.'] } });

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(tabButton.clicked, 1, `${field} should activate ${tabKey}`);
        assert.equal(control.focused, true, `${field} should focus its invalid control`);
    }
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

test('ignores transient media picker file inputs in the dirty fallback', () => {
    const { editor, eventTarget, registry } = makeEditor();
    const uploadInput = new FakeElement('ingredient');
    uploadInput.dataset.ingredientEditorIgnoreDirty = '';

    editor.init();
    eventTarget.dispatch('change', { target: uploadInput });
    eventTarget.dispatch('input', { target: uploadInput });

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(registry.blocksNavigation(), false);
});

test('binds production listeners from the Alpine root instead of requiring an injected target', () => {
    const setup = makeEditor({ eventTarget: null });
    setup.editor.$el = setup.eventTarget;

    setup.editor.init();
    setup.eventTarget.dispatch('input', { target: new FakeElement('guidance') });

    assert.equal(setup.eventTarget.listenerCount('input'), 1);
    assert.equal(setup.editor.stateFor('guidance'), 'dirty');
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

test('captures the flushed scope state at the request interception stage', () => {
    const { editor, state, wire, eventTarget } = makeEditor();

    editor.init();
    eventTarget.dispatch('input', { target: new FakeElement('ingredient') });
    eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    wire.startCommit('save', () => {
        state.data.name = 'Flushed after debounce';
        wire.set('data', state.data);
    });
    wire.emit('ingredient-editor:saved', { scope: 'ingredient' });
    wire.completeCommit();

    assert.equal(editor.stateFor('ingredient'), 'saved');
    assert.equal(editor.baselineFor('ingredient').name, 'Flushed after debounce');
});

test('adopts a canonical material code when the response normalizes the submitted value', () => {
    const setup = makeEditor();

    setup.editor.init();
    edit(setup.wire, 'workspaceMaterialCode', 'code-01');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('material-code') });
    setup.wire.startCommit('saveWorkspaceMaterialCode');
    edit(setup.wire, 'workspaceMaterialCode', 'CODE-01');
    setup.wire.emit('ingredient-editor:saved', {
        scope: 'material-code',
        baseline: 'CODE-01',
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('material-code'), 'saved');
    assert.equal(setup.editor.baselineFor('material-code'), 'CODE-01');
    assert.equal(setup.editor.currentFor('material-code'), 'CODE-01');
});

test('keeps a newer material code edit dirty against the canonical saved value', () => {
    const setup = makeEditor();

    setup.editor.init();
    edit(setup.wire, 'workspaceMaterialCode', 'code-01');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('material-code') });
    setup.wire.startCommit('saveWorkspaceMaterialCode');
    edit(setup.wire, 'workspaceMaterialCode', 'newer-02');
    setup.wire.emit('ingredient-editor:saved', {
        scope: 'material-code',
        baseline: 'CODE-01',
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.editor.baselineFor('material-code'), 'CODE-01');
    assert.equal(setup.editor.currentFor('material-code'), 'newer-02');
});

test('keeps a buffered material code input dirty after an in-flight save succeeds', () => {
    const setup = makeEditor();

    setup.editor.init();
    edit(setup.wire, 'workspaceMaterialCode', 'code-01');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('material-code') });
    setup.wire.startCommit('saveWorkspaceMaterialCode');

    // The DOM has received a newer input, but Livewire has not flushed it into
    // the component state yet.
    setup.eventTarget.dispatch('input', { target: new FakeElement('material-code') });
    setup.wire.emit('ingredient-editor:saved', {
        scope: 'material-code',
        baseline: 'CODE-01',
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.editor.baselineFor('material-code'), 'CODE-01');
    assert.equal(setup.editor.currentFor('material-code'), 'code-01');
    assert.equal(setup.registry.blocksNavigation(), true);

    // The response watcher echoes the submitted canonical value before the
    // buffered DOM value reaches Livewire.
    edit(setup.wire, 'workspaceMaterialCode', 'CODE-01');
    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.registry.blocksNavigation(), true);

    edit(setup.wire, 'workspaceMaterialCode', 'newer-02');
    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.editor.currentFor('material-code'), 'newer-02');

    edit(setup.wire, 'workspaceMaterialCode', 'CODE-01');
    assert.equal(setup.editor.stateFor('material-code'), 'saved');
    assert.equal(setup.registry.blocksNavigation(), false);
});

test('reconciles a newer material code observed before canonical save acknowledgement', () => {
    const setup = makeEditor();

    setup.editor.init();
    edit(setup.wire, 'workspaceMaterialCode', 'code-01');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('material-code') });
    setup.wire.startCommit('saveWorkspaceMaterialCode');
    setup.eventTarget.dispatch('input', { target: new FakeElement('material-code') });
    edit(setup.wire, 'workspaceMaterialCode', 'newer-02');

    setup.wire.emit('ingredient-editor:saved', {
        scope: 'material-code',
        baseline: 'CODE-01',
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('material-code'), 'dirty');
    assert.equal(setup.editor.currentFor('material-code'), 'newer-02');

    setup.editor.markDirty('material-code');
    edit(setup.wire, 'workspaceMaterialCode', 'CODE-01');

    assert.equal(setup.editor.stateFor('material-code'), 'saved');
    assert.equal(setup.editor.currentFor('material-code'), 'CODE-01');
    assert.equal(setup.registry.blocksNavigation(), false);
});

test('adopts the raw nested ingredient state emitted after persistence', () => {
    const setup = makeEditor();
    const rawState = {
        ...structuredClone(setup.state.data),
        components: [{
            ...structuredClone(setup.state.data.components[0]),
            percentage: '60,0',
            repeaterKey: 'row-1',
        }],
        hiddenFormState: { compositionRemovalConfirmed: false },
    };

    setup.editor.init();
    edit(setup.wire, 'data', rawState);
    setup.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    setup.wire.startCommit();
    setup.wire.emit('ingredient-editor:saved', {
        scope: 'ingredient',
        baseline: structuredClone(rawState),
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('ingredient'), 'saved');
    assert.deepEqual(setup.editor.baselineFor('ingredient'), rawState);
    assert.deepEqual(setup.editor.currentFor('ingredient'), rawState);
});

test('installs one navigation guard and removes every listener on destroy', () => {
    const { editor, wire, eventTarget, windowTarget, navigationTarget, registry } = makeEditor();

    editor.init();
    editor.init();

    assert.equal(eventTarget.listenerCount('input'), 1);
    assert.equal(eventTarget.listenerCount('change'), 1);
    assert.equal(eventTarget.listenerCount('submit'), 1);
    assert.equal([...wire.requestInterceptors.values()].flat().length, 5);
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
    assert.equal([...wire.requestInterceptors.values()].flat().length, 0);
    assert.equal(windowTarget.listenerCount(), 0);
    assert.equal(navigationTarget.listenerCount(), 0);
    assert.equal(registry.blocksNavigation(), false);
});

test('rejects a local guidance cancel without running its Livewire action', () => {
    const { editor, eventTarget, confirmations, registry } = makeEditor({
        confirm: () => false,
    });

    editor.init();
    editor.markDirty('guidance');
    const event = eventTarget.dispatch('click', { target: new FakeElement('guidance', null, true) });

    assert.equal(event.defaultPrevented, true);
    assert.equal(confirmations.length, 1);
    assert.equal(editor.stateFor('guidance'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
});

test('accepts a local guidance cancel and clears only guidance state', () => {
    const { editor, eventTarget, confirmations, registry } = makeEditor({
        confirm: () => true,
    });

    editor.init();
    editor.markDirty('guidance');
    editor.markDirty('ingredient');
    const event = eventTarget.dispatch('click', { target: new FakeElement('guidance', null, true) });

    assert.equal(event.defaultPrevented, false);
    assert.equal(confirmations.length, 1);
    assert.equal(editor.stateFor('guidance'), 'saved');
    assert.equal(editor.stateFor('ingredient'), 'dirty');
    assert.equal(registry.blocksNavigation(), true);
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

test('keeps platform guidance confirmation after accepting an unsaved draft warning', () => {
    const { editor, eventTarget, confirmations } = makeEditor();

    editor.init();
    editor.markDirty('guidance');
    eventTarget.dispatch('click', {
        target: new FakeElement('guidance', 'usePlatformGuidance', false, 'Confirm platform guidance?'),
    });

    assert.equal(confirmations.length, 2);
    assert.match(confirmations[0], /unsaved/i);
    assert.equal(confirmations[1], 'Confirm platform guidance?');
    assert.equal(editor.stateFor('guidance'), 'saving');
});

test('adopts inherited guidance when customization opens, then tracks later edits', () => {
    const setup = makeEditor({
        baselines: {
            ingredient: structuredClone(setupPlaceholder().data),
            guidance: { html: null },
            'material-code': 'ARGAN-01',
        },
    });

    setup.editor.init();
    setup.state.workspaceGuidance.html = '<p>Inherited platform guidance.</p>';
    setup.wire.set('workspaceGuidance', setup.state.workspaceGuidance);
    setup.wire.emit('ingredient-editor:baseline', {
        scope: 'guidance',
        baseline: { html: '<p>Inherited platform guidance.</p>' },
    });

    assert.equal(setup.editor.stateFor('guidance'), 'saved');
    setup.wire.set('workspaceGuidance.html', '<p>Edited guidance.</p>');
    assert.equal(setup.editor.stateFor('guidance'), 'dirty');
});

test('acknowledges an initial create from its explicit success event and navigates once', () => {
    const controls = [new FakeControl('input'), new FakeControl('textarea'), new FakeControl('contenteditable')];
    const navigations = [];
    let setup;
    setup = makeEditor({
        isCreate: true,
        navigate(url) {
            navigations.push(url);
            setup.navigationTarget.dispatch('livewire:navigate');
        },
    });
    const form = new FakeForm('ingredient', controls);

    setup.editor.init();
    setup.state.data.name = 'New ingredient';
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();

    assert.equal(setup.editor.stateFor('ingredient'), 'saving');
    assert.equal(controls[0].disabled, true);
    assert.equal(controls[1].readOnly, true);
    assert.equal(controls[2].getAttribute('contenteditable'), 'false');

    setup.wire.emit('ingredient-editor:created', {
        scope: 'ingredient',
        baseline: structuredClone(setup.state.data),
        redirect: '/ingredients/1',
    });
    setup.wire.completeCommit();

    assert.equal(setup.editor.stateFor('ingredient'), 'saved');
    assert.equal(setup.editor.baselineFor('ingredient').name, 'New ingredient');
    assert.equal(controls[0].disabled, false);
    assert.equal(controls[1].readOnly, false);
    assert.equal(controls[2].getAttribute('contenteditable'), 'true');
    assert.deepEqual(navigations, ['/ingredients/1']);
    assert.equal(setup.confirmations.length, 0);
});

test('shows an explicit create success notification once after navigation', () => {
    const form = new FakeForm('ingredient', [new FakeControl('input')]);
    const notifications = [];
    let setup;
    setup = makeEditor({
        isCreate: true,
        dispatchNotification(detail) {
            notifications.push(detail);
        },
        navigate(url) {
            assert.equal(url, '/ingredients/1');
            setup.navigationTarget.dispatch('livewire:navigate');
            setup.navigationTarget.dispatch('livewire:navigated');
        },
    });

    setup.editor.init();
    setup.state.data.name = 'New ingredient';
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();
    setup.wire.emit('ingredient-editor:created', {
        scope: 'ingredient',
        baseline: structuredClone(setup.state.data),
        redirect: '/ingredients/1',
        message: 'Ingredient created.',
    });
    setup.wire.completeCommit();

    assert.deepEqual(notifications, [{ message: 'Ingredient created.', type: 'success' }]);
    assert.equal(setup.navigationTarget.listenerCount('livewire:navigated'), 0);

    setup.navigationTarget.dispatch('livewire:navigated');
    assert.equal(notifications.length, 1);
});

test('keeps a create notification available when the editor is destroyed during navigation', () => {
    const storage = new FakeStorage();
    const notifications = [];
    let setup;
    setup = makeEditor({
        isCreate: true,
        sessionStorage: storage,
        dispatchNotification(detail) {
            notifications.push(detail);
        },
        navigate() {
            setup.navigationTarget.dispatch('livewire:navigate');
            setup.editor.destroy();
        },
    });
    const form = new FakeForm('ingredient', [new FakeControl('input')]);

    setup.editor.init();
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();
    setup.wire.emit('ingredient-editor:created', {
        scope: 'ingredient',
        baseline: structuredClone(setup.state.data),
        redirect: '/ingredients/1',
        message: 'Ingredient created.',
    });
    setup.wire.completeCommit();

    assert.equal(notifications.length, 0);
    assert.equal(consumeIngredientEditorNotification(storage, (detail) => notifications.push(detail)), true);
    assert.deepEqual(notifications, [{ message: 'Ingredient created.', type: 'success' }]);
    assert.equal(consumeIngredientEditorNotification(storage, (detail) => notifications.push(detail)), false);
});

test('does not treat a transport redirect as a successful initial create', () => {
    const controls = [new FakeControl('input'), new FakeControl('contenteditable')];
    const setup = makeEditor({ isCreate: true });
    const form = new FakeForm('ingredient', controls);

    setup.editor.init();
    setup.state.data.name = 'Submitted ingredient';
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();
    setup.wire.completeCommit({ redirect: '/login' });

    assert.equal(controls[0].disabled, false);
    assert.equal(controls[1].getAttribute('contenteditable'), 'true');
    assert.equal(setup.editor.stateFor('ingredient'), 'failed');
    assert.equal(setup.editor.baselineFor('ingredient').name, 'Argan oil');
    assert.equal(setup.registry.blocksNavigation(), true);
    assert.equal(setup.wire.redirectPrevented, true);
});

test('keeps newer create edits protected after explicit success', () => {
    const controls = [new FakeControl('input')];
    const navigations = [];
    const setup = makeEditor({
        isCreate: true,
        navigate(url) {
            navigations.push(url);
        },
    });
    const form = new FakeForm('ingredient', controls);

    setup.editor.init();
    setup.state.data.name = 'Submitted ingredient';
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();
    edit(setup.wire, 'data.name', 'Newer ingredient edit');
    setup.wire.emit('ingredient-editor:created', {
        scope: 'ingredient',
        baseline: { ...setup.state.data, name: 'Submitted ingredient' },
        redirect: '/ingredients/1',
    });
    setup.wire.completeCommit();

    assert.equal(controls[0].disabled, false);
    assert.equal(setup.editor.stateFor('ingredient'), 'dirty');
    assert.equal(setup.editor.baselineFor('ingredient').name, 'Submitted ingredient');
    assert.deepEqual(navigations, []);
});

test('unfreezes and keeps an initial create dirty after a failed commit', () => {
    const controls = [new FakeControl('input'), new FakeControl('contenteditable')];
    const setup = makeEditor({ isCreate: true });
    const form = new FakeForm('ingredient', controls);

    setup.editor.init();
    setup.state.data.name = 'Failed ingredient';
    setup.eventTarget.dispatch('submit', { target: form });
    setup.wire.startCommit();
    setup.wire.failCommit();

    assert.equal(controls[0].disabled, false);
    assert.equal(controls[1].getAttribute('contenteditable'), 'true');
    assert.equal(setup.editor.stateFor('ingredient'), 'failed');
    assert.equal(setup.registry.blocksNavigation(), true);
});

test('does not rewrite the first submitted snapshot during an overlapping second scope commit', () => {
    const setup = makeEditor();

    setup.editor.init();
    edit(setup.wire, 'data.name', 'First submitted name');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('ingredient') });
    setup.wire.startCommit();

    edit(setup.wire, 'data.name', 'Newer ingredient edit');
    edit(setup.wire, 'workspaceGuidance.html', '<p>Submitted guidance.</p>');
    setup.eventTarget.dispatch('submit', { target: new FakeElement('guidance') });
    setup.wire.startCommit('saveWorkspaceGuidance');

    setup.wire.emit('ingredient-editor:saved', { scope: 'ingredient' });
    setup.wire.completeCommit({}, 0);

    assert.equal(setup.editor.baselineFor('ingredient').name, 'First submitted name');
    assert.equal(setup.editor.stateFor('ingredient'), 'dirty');
    assert.equal(setup.registry.blocksNavigation(), true);
});

test('serializes nested values with stable object-key order', () => {
    const left = { rich: { type: 'doc', content: [{ attrs: { level: 2, align: null } }] }, media: [{ id: 1, role: 'featured' }] };
    const right = { media: [{ role: 'featured', id: 1 }], rich: { content: [{ attrs: { align: null, level: 2 } }], type: 'doc' } };

    assert.equal(stableSerialize(left), stableSerialize(right));
});

function setupPlaceholder() {
    return {
        data: {
            name: 'Argan oil',
            components: [{ ingredient_id: 1, percentage: 60 }],
            media: [{ id: 10, role: 'featured' }],
            description: { type: 'doc', content: [{ type: 'paragraph', content: [{ text: 'Useful.' }] }] },
        },
    };
}
