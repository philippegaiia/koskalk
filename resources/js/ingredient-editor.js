const SCOPE_PATHS = {
    ingredient: 'data',
    guidance: 'workspaceGuidance',
    'material-code': 'workspaceMaterialCode',
};

const SCOPE_KEYS = Object.keys(SCOPE_PATHS);
const SAVED_EVENT = 'ingredient-editor:saved';
const CANCELLED_EVENT = 'ingredient-editor:cancelled';

const DEFAULT_LABELS = {
    saved: 'All changes saved',
    dirty: 'Unsaved changes',
    saving: 'Saving…',
    failed: 'Save failed',
    leaveWarning: 'You have unsaved changes. Leave this page?',
    replaceGuidance: 'You have an unsaved guidance draft. Replace it?',
};

function cloneValue(value) {
    if (value === undefined || value === null) {
        return value;
    }

    if (typeof structuredClone === 'function') {
        try {
            return structuredClone(value);
        } catch (error) {
            void error;
        }
    }

    if (Array.isArray(value)) {
        return value.map((item) => cloneValue(item));
    }

    if (typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [key, cloneValue(item)]),
        );
    }

    return value;
}

function stableValue(value) {
    if (value === undefined) {
        return null;
    }

    if (value === null || typeof value !== 'object') {
        return value;
    }

    if (Array.isArray(value)) {
        return value.map((item) => stableValue(item));
    }

    return Object.keys(value)
        .sort()
        .reduce((result, key) => {
            result[key] = stableValue(value[key]);

            return result;
        }, {});
}

export function stableSerialize(value) {
    return JSON.stringify(stableValue(value));
}

function fallbackRegistry() {
    const states = new Map();

    return {
        set(key, state) {
            states.set(key, state);
        },

        remove(key) {
            states.delete(key);
        },

        blocksNavigation() {
            return [...states.values()].some((state) => ['dirty', 'saving', 'failed'].includes(state));
        },
    };
}

function defaultConfirm(message) {
    if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
        return window.confirm(message);
    }

    return true;
}

function isEditable(editable, scope) {
    return editable[scope] !== false;
}

function stateFromEventTarget(target) {
    const scopeElement = target?.closest?.('[data-ingredient-scope]') ?? target;
    const scope = scopeElement?.dataset?.ingredientScope;

    return SCOPE_KEYS.includes(scope) ? scope : null;
}

function replaceActionFromEventTarget(target) {
    const actionElement = target?.closest?.('[data-ingredient-guidance-replace]');
    const action = actionElement?.dataset?.ingredientGuidanceReplace;

    return typeof action === 'string' && action !== '' ? action : null;
}

export function createIngredientEditor(options = {}, createRegistry = null) {
    const registry = options.registry
        ?? (typeof createRegistry === 'function' ? createRegistry() : fallbackRegistry());
    const wire = options.wire ?? {};
    const eventTarget = options.eventTarget ?? options.element ?? null;
    const windowTarget = options.windowTarget
        ?? (typeof window === 'undefined' ? null : window);
    const navigationTarget = options.navigationTarget
        ?? (typeof document === 'undefined' ? null : document);
    const paths = { ...SCOPE_PATHS, ...(options.paths ?? {}) };
    const editable = { ...Object.fromEntries(SCOPE_KEYS.map((scope) => [scope, true])), ...(options.editable ?? {}) };
    const initialBaselines = options.baselines ?? {};
    const labels = { ...DEFAULT_LABELS, ...(options.labels ?? {}) };
    const read = options.read ?? ((scope) => {
        if (typeof wire.get === 'function') {
            return wire.get(paths[scope]);
        }

        return undefined;
    });
    const watch = options.watch ?? ((path, callback) => wire.$watch?.(path, callback));
    const listen = options.on ?? ((name, callback) => wire.$on?.(name, callback));
    const hook = options.hook ?? ((name, callback) => wire.$hook?.(name, callback));
    const invoke = options.invoke ?? ((method) => wire[method]?.());
    const confirm = options.confirm ?? defaultConfirm;

    const scopeBaselines = {};
    const scopeValues = {};
    const scopeSequences = Object.fromEntries(SCOPE_KEYS.map((scope) => [scope, 0]));
    const pendingSaves = new Map();
    const unsubscriptions = [];

    const editor = {
        registry,
        paths,
        labels,
        scopeStates: Object.fromEntries(SCOPE_KEYS.map((scope) => [scope, 'saved'])),
        scopeBaselines,
        scopeValues,
        isInitialized: false,
        isDestroyed: false,
        inputHandler: null,
        changeHandler: null,
        submitHandler: null,
        clickHandler: null,
        beforeUnloadHandler: null,
        navigateHandler: null,

        init() {
            if (this.isInitialized) {
                return this;
            }

            this.isInitialized = true;
            this.isDestroyed = false;

            for (const scope of SCOPE_KEYS) {
                const baseline = Object.prototype.hasOwnProperty.call(initialBaselines, scope)
                    ? initialBaselines[scope]
                    : read(scope);

                scopeBaselines[scope] = cloneValue(baseline);
                scopeValues[scope] = cloneValue(baseline);
                this.setScopeState(scope, 'saved');

                if (!isEditable(editable, scope)) {
                    continue;
                }

                const unwatch = watch(paths[scope], (value) => this.observe(scope, value));

                if (typeof unwatch === 'function') {
                    unsubscriptions.push(unwatch);
                }
            }

            const unlistenSaved = listen(SAVED_EVENT, (detail = {}) => this.completeSave(detail.scope, detail));
            const unlistenCancelled = listen(CANCELLED_EVENT, (detail = {}) => this.cancelScope(detail.scope, detail));

            if (typeof unlistenSaved === 'function') {
                unsubscriptions.push(unlistenSaved);
            }

            if (typeof unlistenCancelled === 'function') {
                unsubscriptions.push(unlistenCancelled);
            }

            const unhook = hook('commit', ({ succeed, fail } = {}) => {
                this.capturePendingValues();
                const pendingScopes = [...pendingSaves.keys()];

                succeed?.(() => {
                    queueMicrotask(() => {
                        for (const scope of pendingScopes) {
                            if (pendingSaves.has(scope)) {
                                this.failScope(scope);
                            }
                        }
                    });
                });

                fail?.(() => {
                    for (const scope of pendingScopes) {
                        this.failScope(scope);
                    }
                });
            });

            if (typeof unhook === 'function') {
                unsubscriptions.push(unhook);
            }

            if (eventTarget) {
                this.inputHandler = (event) => this.handleInput(event);
                this.changeHandler = (event) => this.handleInput(event);
                this.submitHandler = (event) => this.handleSubmit(event);
                this.clickHandler = (event) => this.handleClick(event);

                eventTarget.addEventListener('input', this.inputHandler, true);
                eventTarget.addEventListener('change', this.changeHandler, true);
                eventTarget.addEventListener('submit', this.submitHandler, true);
                eventTarget.addEventListener('click', this.clickHandler, true);
            }

            this.installUnsavedChangesGuard();

            return this;
        },

        destroy() {
            if (!this.isInitialized) {
                return;
            }

            this.isDestroyed = true;

            if (eventTarget) {
                eventTarget.removeEventListener('input', this.inputHandler, true);
                eventTarget.removeEventListener('change', this.changeHandler, true);
                eventTarget.removeEventListener('submit', this.submitHandler, true);
                eventTarget.removeEventListener('click', this.clickHandler, true);
            }

            this.removeUnsavedChangesGuard();

            for (const unsubscribe of unsubscriptions.splice(0)) {
                unsubscribe();
            }

            pendingSaves.clear();

            for (const scope of SCOPE_KEYS) {
                registry.remove(scope);
            }

            this.isInitialized = false;
        },

        stateFor(scope) {
            return this.scopeStates[scope] ?? 'saved';
        },

        baselineFor(scope) {
            return cloneValue(this.scopeBaselines[scope]);
        },

        currentFor(scope) {
            return cloneValue(this.scopeValues[scope]);
        },

        statusText(scope) {
            return labels[this.stateFor(scope)] ?? labels.saved;
        },

        scopeForElement(element) {
            return stateFromEventTarget(element);
        },

        setScopeState(scope, state) {
            if (!SCOPE_KEYS.includes(scope)) {
                return;
            }

            this.scopeStates[scope] = state;
            registry.set(scope, state);
        },

        observe(scope, value) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope)) {
                return;
            }

            const nextValue = cloneValue(value);
            const previousSignature = stableSerialize(scopeValues[scope]);
            const nextSignature = stableSerialize(nextValue);
            scopeValues[scope] = nextValue;

            if (previousSignature !== nextSignature) {
                scopeSequences[scope] += 1;
            }

            if (!isEditable(editable, scope) || pendingSaves.has(scope)) {
                return;
            }

            if (this.stateFor(scope) === 'failed') {
                return;
            }

            this.setScopeState(
                scope,
                stableSerialize(scopeBaselines[scope]) === nextSignature ? 'saved' : 'dirty',
            );
        },

        markDirty(scope) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            scopeSequences[scope] += 1;

            if (!['saving', 'failed'].includes(this.stateFor(scope))) {
                this.setScopeState(scope, 'dirty');
            }
        },

        beginSave(scope, captureValue = true) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            if (captureValue) {
                this.captureValue(scope);
            }

            pendingSaves.set(scope, {
                sequence: scopeSequences[scope],
                value: cloneValue(scopeValues[scope]),
            });
            this.setScopeState(scope, 'saving');
        },

        captureValue(scope) {
            const value = read(scope);

            if (value === undefined) {
                return;
            }

            const nextValue = cloneValue(value);
            if (stableSerialize(scopeValues[scope]) !== stableSerialize(nextValue)) {
                scopeSequences[scope] += 1;
            }
            scopeValues[scope] = nextValue;
        },

        capturePendingValues() {
            for (const [scope, pending] of pendingSaves.entries()) {
                this.captureValue(scope);
                pending.sequence = scopeSequences[scope];
                pending.value = cloneValue(scopeValues[scope]);
            }
        },

        completeSave(scope, detail = {}) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            const pending = pendingSaves.get(scope);
            const savedValue = Object.prototype.hasOwnProperty.call(detail, 'baseline')
                ? detail.baseline
                : pending?.value ?? scopeValues[scope] ?? read(scope);
            const savedSignature = stableSerialize(savedValue);
            const currentSignature = stableSerialize(scopeValues[scope]);
            const changedDuringSave = pending !== undefined
                && pending.sequence !== scopeSequences[scope];

            scopeBaselines[scope] = cloneValue(savedValue);
            pendingSaves.delete(scope);

            this.setScopeState(
                scope,
                changedDuringSave || currentSignature !== savedSignature ? 'dirty' : 'saved',
            );
        },

        failScope(scope) {
            if (!SCOPE_KEYS.includes(scope)) {
                return;
            }

            pendingSaves.delete(scope);
            this.setScopeState(scope, 'failed');
        },

        cancelScope(scope, detail = {}) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            const currentValue = Object.prototype.hasOwnProperty.call(detail, 'baseline')
                ? detail.baseline
                : read(scope) ?? scopeValues[scope];

            scopeBaselines[scope] = cloneValue(currentValue);
            scopeValues[scope] = cloneValue(currentValue);
            pendingSaves.delete(scope);
            this.setScopeState(scope, 'saved');
        },

        handleInput(event) {
            this.markDirty(stateFromEventTarget(event.target));
        },

        handleSubmit(event) {
            this.beginSave(stateFromEventTarget(event.target), false);
        },

        handleClick(event) {
            const action = replaceActionFromEventTarget(event.target);

            if (action === null || this.isDestroyed) {
                return;
            }

            if (this.stateFor('guidance') !== 'saved') {
                event.preventDefault();
                event.stopImmediatePropagation?.();

                if (confirm(labels.replaceGuidance)) {
                    this.beginSave('guidance');
                    invoke(action);
                }

                return;
            }

            queueMicrotask(() => {
                if (!event.defaultPrevented && this.isInitialized) {
                    this.beginSave('guidance');
                }
            });
        },

        blocksNavigation() {
            return registry.blocksNavigation();
        },

        installUnsavedChangesGuard() {
            if (this.beforeUnloadHandler || this.navigateHandler) {
                return;
            }

            this.beforeUnloadHandler = (event) => {
                if (!this.blocksNavigation()) {
                    return;
                }

                event.preventDefault();
                event.returnValue = '';
            };

            this.navigateHandler = (event) => {
                if (!this.blocksNavigation()) {
                    return;
                }

                if (!confirm(labels.leaveWarning)) {
                    event.preventDefault();
                }
            };

            windowTarget?.addEventListener('beforeunload', this.beforeUnloadHandler);
            navigationTarget?.addEventListener('livewire:navigate', this.navigateHandler);
        },

        removeUnsavedChangesGuard() {
            if (this.beforeUnloadHandler) {
                windowTarget?.removeEventListener('beforeunload', this.beforeUnloadHandler);
                this.beforeUnloadHandler = null;
            }

            if (this.navigateHandler) {
                navigationTarget?.removeEventListener('livewire:navigate', this.navigateHandler);
                this.navigateHandler = null;
            }
        },
    };

    return editor;
}
