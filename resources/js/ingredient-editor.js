const SCOPE_PATHS = {
    ingredient: 'data',
    guidance: 'workspaceGuidance',
    'material-code': 'workspaceMaterialCode',
};

const SCOPE_KEYS = Object.keys(SCOPE_PATHS);
const SAVED_EVENT = 'ingredient-editor:saved';
const CREATED_EVENT = 'ingredient-editor:created';
const CANCELLED_EVENT = 'ingredient-editor:cancelled';
const BASELINE_EVENT = 'ingredient-editor:baseline';
const SAVE_METHODS = {
    ingredient: 'save',
    guidance: 'saveWorkspaceGuidance',
    'material-code': 'saveWorkspaceMaterialCode',
};
const SCOPE_METHODS = {
    ingredient: ['save'],
    guidance: ['saveWorkspaceGuidance', 'usePlatformGuidance', 'useWorkspaceGuidance'],
    'material-code': ['saveWorkspaceMaterialCode'],
};
const CANCEL_METHODS = {
    guidance: 'cancelWorkspaceGuidanceCustomization',
};

const DEFAULT_LABELS = {
    saved: 'All changes saved',
    dirty: 'Unsaved changes',
    saving: 'Saving…',
    failed: 'Save failed',
    leaveWarning: 'You have unsaved changes. Leave this page?',
    replaceGuidance: 'You have an unsaved guidance draft. Replace it?',
    cancelGuidance: 'You have an unsaved guidance draft. Discard it?',
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

function replaceConfirmationFromEventTarget(target) {
    const actionElement = target?.closest?.('[data-ingredient-guidance-replace]');
    const confirmation = actionElement?.dataset?.ingredientGuidanceConfirm;

    return typeof confirmation === 'string' && confirmation !== '' ? confirmation : null;
}

function hasLocalCancelFromEventTarget(target) {
    const actionElement = target?.closest?.('[data-ingredient-editor-local-cancel]');

    return actionElement?.dataset?.ingredientEditorLocalCancel === 'guidance';
}

export function createIngredientEditor(options = {}, createRegistry = null) {
    const registry = options.registry
        ?? (typeof createRegistry === 'function' ? createRegistry() : fallbackRegistry());
    const wire = options.wire ?? {};
    const configuredEventTarget = options.eventTarget ?? options.element ?? null;
    const windowTarget = options.windowTarget
        ?? (typeof window === 'undefined' ? null : window);
    const navigationTarget = options.navigationTarget
        ?? (typeof document === 'undefined' ? null : document);
    const paths = { ...SCOPE_PATHS, ...(options.paths ?? {}) };
    const saveMethods = { ...SAVE_METHODS, ...(options.saveMethods ?? {}) };
    const scopeMethods = Object.fromEntries(
        SCOPE_KEYS.map((scope) => {
            const primaryMethod = saveMethods[scope];
            const defaultMethods = SCOPE_METHODS[scope] ?? [];
            const methods = options.scopeMethods?.[scope]
                ?? [primaryMethod, ...defaultMethods.filter((method) => method !== SAVE_METHODS[scope])];

            return [scope, methods.filter(Boolean)];
        }),
    );
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
    const interceptRequest = options.interceptRequest
        ?? ((method, callback) => wire.$interceptRequest?.(method, callback));
    const invoke = options.invoke ?? ((method) => wire[method]?.());
    const navigate = options.navigate ?? ((url) => {
        if (typeof window !== 'undefined' && window.Livewire?.navigate) {
            return window.Livewire.navigate(url);
        }

        if (typeof window !== 'undefined' && typeof window.location?.assign === 'function') {
            return window.location.assign(url);
        }

        return undefined;
    });
    const confirm = options.confirm ?? defaultConfirm;

    const scopeBaselines = {};
    const scopeValues = {};
    const scopeSequences = Object.fromEntries(SCOPE_KEYS.map((scope) => [scope, 0]));
    const pendingSaves = new Map();
    const pendingCancels = new Map();
    const createAcknowledgements = new Map();
    const unsubscriptions = [];
    let boundEventTarget = null;
    let navigationAllowance = false;

    const editor = {
        registry,
        paths,
        labels,
        isCreate: Boolean(options.isCreate),
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
        createSubmission: null,
        frozenCreateControls: [],
        boundEventTarget: null,

        init() {
            if (this.isInitialized) {
                return this;
            }

            this.isInitialized = true;
            this.isDestroyed = false;
            boundEventTarget = this.$el ?? configuredEventTarget;
            this.boundEventTarget = boundEventTarget;

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

                for (const method of scopeMethods[scope]) {
                    const unlistenRequest = interceptRequest(method, (details = {}) => {
                        this.handleRequest(scope, details);
                    });

                    if (typeof unlistenRequest === 'function') {
                        unsubscriptions.push(unlistenRequest);
                    }
                }
            }

            const unlistenSaved = listen(SAVED_EVENT, (detail = {}) => this.completeSave(detail.scope, detail));
            const unlistenCreated = listen(CREATED_EVENT, (detail = {}) => this.completeCreate(detail));
            const unlistenCancelled = listen(CANCELLED_EVENT, (detail = {}) => this.cancelScope(detail.scope, detail));
            const unlistenBaseline = listen(BASELINE_EVENT, (detail = {}) => this.adoptBaseline(detail.scope, detail));

            if (typeof unlistenSaved === 'function') {
                unsubscriptions.push(unlistenSaved);
            }

            if (typeof unlistenCreated === 'function') {
                unsubscriptions.push(unlistenCreated);
            }

            if (typeof unlistenCancelled === 'function') {
                unsubscriptions.push(unlistenCancelled);
            }

            if (typeof unlistenBaseline === 'function') {
                unsubscriptions.push(unlistenBaseline);
            }

            const unhook = hook('commit', ({ succeed, fail, commit } = {}) => {
                const commitMethods = (commit?.calls ?? [])
                    .map(({ method }) => method)
                    .filter(Boolean);
                const commitScope = SCOPE_KEYS.find((scope) => (
                    scopeMethods[scope] ?? []
                ).some((method) => commitMethods.includes(method)));
                const cancelScope = SCOPE_KEYS.find((scope) => (
                    CANCEL_METHODS[scope] !== undefined
                    && commitMethods.includes(CANCEL_METHODS[scope])
                ));

                const pendingScopes = commitScope !== undefined && pendingSaves.has(commitScope)
                    ? [commitScope]
                    : [];
                const pendingCancelScopes = cancelScope !== undefined && pendingCancels.has(cancelScope)
                    ? [cancelScope]
                    : [];

                succeed?.(() => {
                    queueMicrotask(() => {
                        for (const scope of pendingScopes) {
                            if (pendingSaves.has(scope)) {
                                this.failScope(scope);
                            }
                        }

                        for (const scope of pendingCancelScopes) {
                            if (pendingCancels.has(scope)) {
                                this.restoreCancelledScope(scope);
                            }
                        }
                    });
                });

                fail?.(() => {
                    for (const scope of pendingScopes) {
                        this.failScope(scope);
                    }

                    for (const scope of pendingCancelScopes) {
                        this.restoreCancelledScope(scope);
                    }
                });
            });

            if (typeof unhook === 'function') {
                unsubscriptions.push(unhook);
            }

            if (boundEventTarget) {
                this.inputHandler = (event) => this.handleInput(event);
                this.changeHandler = (event) => this.handleInput(event);
                this.submitHandler = (event) => this.handleSubmit(event);
                this.clickHandler = (event) => this.handleClick(event);

                boundEventTarget.addEventListener('input', this.inputHandler, true);
                boundEventTarget.addEventListener('change', this.changeHandler, true);
                boundEventTarget.addEventListener('submit', this.submitHandler, true);
                boundEventTarget.addEventListener('click', this.clickHandler, true);
            }

            this.installUnsavedChangesGuard();

            return this;
        },

        destroy() {
            if (!this.isInitialized) {
                return;
            }

            this.isDestroyed = true;

            this.restoreCreateForm();

            if (boundEventTarget) {
                boundEventTarget.removeEventListener('input', this.inputHandler, true);
                boundEventTarget.removeEventListener('change', this.changeHandler, true);
                boundEventTarget.removeEventListener('submit', this.submitHandler, true);
                boundEventTarget.removeEventListener('click', this.clickHandler, true);
            }

            this.removeUnsavedChangesGuard();

            for (const unsubscribe of unsubscriptions.splice(0)) {
                unsubscribe();
            }

            pendingSaves.clear();
            pendingCancels.clear();
            createAcknowledgements.clear();
            navigationAllowance = false;

            for (const scope of SCOPE_KEYS) {
                registry.remove(scope);
            }

            this.isInitialized = false;
            boundEventTarget = null;
            this.boundEventTarget = null;
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

            if (!isEditable(editable, scope) || pendingSaves.has(scope) || pendingCancels.has(scope)) {
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

            createAcknowledgements.delete(scope);

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

        captureSubmittedValue(scope) {
            const pending = pendingSaves.get(scope);

            if (!pending) {
                return;
            }

            this.captureValue(scope);
            pending.sequence = scopeSequences[scope];
            pending.value = cloneValue(scopeValues[scope]);
        },

        handleRequest(scope, { onRedirect } = {}) {
            if (!pendingSaves.has(scope)) {
                return;
            }

            this.captureSubmittedValue(scope);

            onRedirect?.(({ preventDefault } = {}) => {
                if (scope !== 'ingredient' || !this.isCreate || !this.createSubmission) {
                    return;
                }

                this.failScope(scope);
                preventDefault?.();
            });
        },

        completeCreate(detail = {}) {
            if (
                this.isDestroyed
                || !this.isCreate
                || detail.scope !== 'ingredient'
                || !isEditable(editable, 'ingredient')
                || !this.createSubmission
                || !pendingSaves.has('ingredient')
                || !Object.prototype.hasOwnProperty.call(detail, 'baseline')
            ) {
                return;
            }

            createAcknowledgements.set('ingredient', cloneValue(detail.baseline));
            this.completeSave('ingredient', { baseline: detail.baseline });
            this.restoreCreateForm();

            if (this.stateFor('ingredient') === 'saved') {
                this.navigateAfterCreate(detail.redirect);
            }
        },

        completeSave(scope, detail = {}) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            const pending = pendingSaves.get(scope);
            if (pending === undefined && createAcknowledgements.has(scope)) {
                return;
            }

            const hasCanonicalBaseline = Object.prototype.hasOwnProperty.call(detail, 'baseline');
            const savedValue = hasCanonicalBaseline
                ? detail.baseline
                : pending !== undefined
                    ? pending.value
                    : scopeValues[scope] ?? read(scope);
            const currentSignature = stableSerialize(scopeValues[scope]);
            const submittedSignature = pending === undefined
                ? null
                : stableSerialize(pending.value);

            if (hasCanonicalBaseline && pending !== undefined && currentSignature === submittedSignature) {
                scopeValues[scope] = cloneValue(savedValue);
            }

            scopeBaselines[scope] = cloneValue(savedValue);
            pendingSaves.delete(scope);

            this.setScopeState(
                scope,
                stableSerialize(scopeValues[scope]) === stableSerialize(savedValue) ? 'saved' : 'dirty',
            );

            if (scope === 'ingredient' && this.isCreate) {
                queueMicrotask(() => {
                    if (this.isInitialized) {
                        this.restoreCreateForm();
                    }
                });
            }
        },

        navigateAfterCreate(url) {
            if (typeof url !== 'string' || url === '') {
                return;
            }

            navigationAllowance = true;

            try {
                navigate(url);
            } catch (error) {
                void error;
            } finally {
                queueMicrotask(() => {
                    navigationAllowance = false;
                });
            }
        },

        failScope(scope) {
            if (!SCOPE_KEYS.includes(scope)) {
                return;
            }

            pendingSaves.delete(scope);
            createAcknowledgements.delete(scope);
            this.setScopeState(scope, 'failed');

            if (scope === 'ingredient' && this.isCreate) {
                this.restoreCreateForm();
            }
        },

        adoptBaseline(scope, detail = {}) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            if (pendingSaves.has(scope) || pendingCancels.has(scope)) {
                return;
            }

            const baseline = Object.prototype.hasOwnProperty.call(detail, 'baseline')
                ? detail.baseline
                : read(scope);
            const current = read(scope) ?? baseline;
            const currentSignature = stableSerialize(current);

            scopeBaselines[scope] = cloneValue(baseline);
            scopeValues[scope] = cloneValue(current);

            this.setScopeState(
                scope,
                stableSerialize(baseline) === currentSignature ? 'saved' : 'dirty',
            );
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
            pendingCancels.delete(scope);
            createAcknowledgements.delete(scope);
            this.setScopeState(scope, 'saved');
        },

        discardScope(scope) {
            if (this.isDestroyed || !SCOPE_KEYS.includes(scope) || !isEditable(editable, scope)) {
                return;
            }

            pendingCancels.set(scope, {
                baseline: cloneValue(scopeBaselines[scope]),
                value: cloneValue(scopeValues[scope]),
                state: this.stateFor(scope),
            });
            this.setScopeState(scope, 'saved');
        },

        restoreCancelledScope(scope) {
            const pending = pendingCancels.get(scope);

            if (!pending) {
                return;
            }

            pendingCancels.delete(scope);
            scopeBaselines[scope] = pending.baseline;
            scopeValues[scope] = pending.value;
            this.setScopeState(scope, pending.state);
        },

        handleInput(event) {
            const scope = stateFromEventTarget(event.target);

            if (scope === 'ingredient' && this.createSubmission) {
                event.preventDefault();
                event.stopImmediatePropagation?.();

                return;
            }

            this.markDirty(scope);
        },

        handleSubmit(event) {
            const scope = stateFromEventTarget(event.target);

            if (scope === 'ingredient' && this.isCreate) {
                this.freezeCreateForm(event.target);
            }

            this.beginSave(scope, false);
        },

        handleClick(event) {
            if (hasLocalCancelFromEventTarget(event.target)) {
                if (this.stateFor('guidance') === 'saved') {
                    return;
                }

                if (!confirm(labels.cancelGuidance)) {
                    event.preventDefault();
                    event.stopImmediatePropagation?.();

                    return;
                }

                this.discardScope('guidance');

                return;
            }

            const action = replaceActionFromEventTarget(event.target);

            if (action === null || this.isDestroyed) {
                return;
            }

            if (this.stateFor('guidance') !== 'saved') {
                event.preventDefault();
                event.stopImmediatePropagation?.();

                if (!confirm(labels.replaceGuidance)) {
                    return;
                }

                const confirmation = replaceConfirmationFromEventTarget(event.target);
                if (confirmation !== null && !confirm(confirmation)) {
                    return;
                }

                this.beginSave('guidance');
                invoke(action);

                return;
            }
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
                if (navigationAllowance) {
                    navigationAllowance = false;

                    return;
                }

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

        freezeCreateForm(form) {
            if (!this.isCreate || this.createSubmission || !form?.querySelectorAll) {
                return;
            }

            const controls = form.querySelectorAll('input, textarea, select, button, [contenteditable]');
            const frozenControls = [];

            for (const control of controls) {
                const hadDisabled = 'disabled' in control;
                const hadReadOnly = 'readOnly' in control;
                const contentEditableValue = control.getAttribute?.('contenteditable');
                const isContentEditable = (
                    contentEditableValue !== null
                    && contentEditableValue !== undefined
                ) || control.contentEditable === 'true'
                    || control.isContentEditable === true;

                if (hadDisabled) {
                    frozenControls.push({ control, property: 'disabled', value: control.disabled });
                    control.disabled = true;
                }

                if (hadReadOnly && !isContentEditable) {
                    frozenControls.push({ control, property: 'readOnly', value: control.readOnly });
                    control.readOnly = true;
                }

                if (isContentEditable) {
                    frozenControls.push({
                        control,
                        attribute: 'contenteditable',
                        value: contentEditableValue,
                    });
                    control.setAttribute('contenteditable', 'false');
                }
            }

            this.frozenCreateControls = frozenControls;
            this.createSubmission = { form };
        },

        restoreCreateForm() {
            for (const frozenControl of this.frozenCreateControls) {
                if (frozenControl.attribute === 'contenteditable') {
                    if (frozenControl.value === null || frozenControl.value === undefined) {
                        frozenControl.control.removeAttribute('contenteditable');
                    } else {
                        frozenControl.control.setAttribute('contenteditable', frozenControl.value);
                    }

                    continue;
                }

                frozenControl.control[frozenControl.property] = frozenControl.value;
            }

            this.frozenCreateControls = [];
            this.createSubmission = null;
        },
    };

    return editor;
}
