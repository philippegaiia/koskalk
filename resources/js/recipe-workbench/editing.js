const EDITING_POLL_INTERVAL = 15_000;

function enqueueEditingRead(runtime, operation) {
    const queued = runtime.mutationQueue.then(operation, operation);
    runtime.mutationQueue = queued.then(() => undefined, () => undefined);

    return queued;
}

function editingToken() {
    const cryptoApi = globalThis.crypto ?? globalThis.window?.crypto;

    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    if (typeof cryptoApi?.getRandomValues === 'function') {
        const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');

        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    return null;
}

function isVisibleAndFocused() {
    if (typeof document === 'undefined' || document.visibilityState === 'hidden') {
        return false;
    }

    return typeof document.hasFocus !== 'function' || document.hasFocus();
}

function revisionChanged(workbench, editing) {
    return Number(editing.recipe_revision ?? 0) !== Number(workbench.editingRecipeRevision ?? 0)
        || (editing.current_version_id ?? null) !== (workbench.editingVersionId ?? null)
        || Number(editing.costing_revision ?? 0) !== Number(workbench.editingCostingRevision ?? 0);
}

function leaseErrorResponse(workbench) {
    return {
        ok: false,
        message: workbench.editingMessage || workbench.t('editing.unavailable'),
        errors: { editing_lease: [workbench.editingMessage || workbench.t('editing.unavailable')] },
    };
}

export function createEditingSection(payload) {
    const canEditRecipe = payload.canEditRecipe !== false;
    const editingRequired = Boolean(payload.canPersist && payload.recipe?.id && payload.editing);
    const runtime = {
        mutationQueue: Promise.resolve(),
        acquisitionPromise: null,
        pollPromise: null,
        pollTimer: null,
        focusHandler: null,
        visibilityHandler: null,
        departureHandler: null,
        restoreHandler: null,
        departing: false,
        destroyed: false,
        releasePromise: null,
        resumeOnRestore: false,
    };

    return {
        canEditRecipe,
        editingRequired,
        editingToken: editingRequired ? editingToken() : null,
        editingStatus: editingRequired ? 'available' : 'inactive',
        editingServerState: payload.editing ?? null,
        editingRecipeRevision: Number(payload.editing?.recipe_revision ?? 0),
        editingVersionId: payload.editing?.current_version_id ?? null,
        editingCostingRevision: Number(payload.editing?.costing_revision ?? 0),
        editingOwnsLease: false,
        editingStarted: false,
        editingStale: false,
        editingTakeoverOpen: false,
        editingTakeoverReason: '',
        editingMessage: '',

        get isEditingUnavailable() {
            return (this.editingRequired && this.editingStatus !== 'acquired') || runtime.departing;
        },

        get canWriteRecipe() {
            return this.canEditRecipe && !this.isFormulaLocked && !this.isEditingUnavailable;
        },

        get canSubmitRecipeControl() {
            return this.canEditRecipe && !this.isEditingUnavailable;
        },

        get editingHolderName() {
            return this.editingServerState?.holder_name ?? '';
        },

        get editingExpiryLabel() {
            const expiresAt = this.editingServerState?.expires_at;

            if (!expiresAt) {
                return '';
            }

            const date = new Date(expiresAt);

            return Number.isNaN(date.getTime())
                ? String(expiresAt)
                : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
        },

        get canTakeOverEditing() {
            return this.editingRequired
                && Boolean(this.editingToken)
                && this.editingStatus === 'blocked'
                && Boolean(this.editingServerState?.can_take_over);
        },

        async activateSavedRecipeEditing(response) {
            const recipe = response?.snapshot?.draft?.recipe;

            if (!response?.ok || !response.editing || !recipe?.id || this.recipeId != null
                || this.editingRequired || !this.canEditRecipe) {
                return null;
            }

            this.recipeId = recipe.id;
            this.currentVersionId = recipe.current_version_id ?? this.currentVersionId;
            this.currentVersionNumber = recipe.version_number ?? this.currentVersionNumber;
            this.currentVersionIsDraft = recipe.is_current ?? this.currentVersionIsDraft;
            this.editingRequired = true;
            this.editingToken = editingToken();
            this.recordEditingMutation(response);

            return this.startEditingProtection();
        },

        async startEditingProtection() {
            if (!this.editingRequired || this.editingStarted || runtime.departing) {
                return null;
            }

            this.editingStarted = true;
            this.editingStatus = 'acquiring';
            this.editingMessage = '';

            runtime.focusHandler = () => {
                if (isVisibleAndFocused()) {
                    void this.pollEditingState();
                }
            };
            runtime.visibilityHandler = () => {
                if (isVisibleAndFocused()) {
                    void this.pollEditingState();
                }
            };
            runtime.departureHandler = () => this.releaseEditingOnDeparture();
            runtime.restoreHandler = event => {
                if (event.persisted) {
                    void this.restoreEditingProtection();
                }
            };

            if (typeof window !== 'undefined') {
                window.addEventListener?.('focus', runtime.focusHandler);
                window.addEventListener?.('pagehide', runtime.departureHandler);
                window.addEventListener?.('pageshow', runtime.restoreHandler);
            }
            if (typeof document !== 'undefined') {
                document.addEventListener?.('visibilitychange', runtime.visibilityHandler);
                document.addEventListener?.('livewire:navigating', runtime.departureHandler);
            }

            runtime.pollTimer = globalThis.setInterval(() => {
                if (isVisibleAndFocused()) {
                    void this.pollEditingState();
                }
            }, EDITING_POLL_INTERVAL);

            if (!this.editingToken) {
                this.markEditingLost(this.t('editing.token_unavailable'));

                return null;
            }

            return this.acquireEditingReservation(false);
        },

        async acquireEditingReservation(waitForPoll = true) {
            if (runtime.departing) {
                return leaseErrorResponse(this);
            }

            if (!this.editingToken) {
                this.markEditingLost(this.t('editing.token_unavailable'));

                return leaseErrorResponse(this);
            }

            if (runtime.acquisitionPromise) {
                return runtime.acquisitionPromise;
            }

            this.editingStatus = 'acquiring';
            this.editingMessage = '';

            if (waitForPoll && runtime.pollPromise) {
                await runtime.pollPromise;
                if (runtime.departing) {
                    return leaseErrorResponse(this);
                }
                this.editingStatus = 'acquiring';
            }

            const promise = (async () => {
                try {
                    const response = await this.$wire.beginEditing(this.editingToken);
                    this.applyEditingStatusResponse(response, 'acquire');

                    return response;
                } catch (error) {
                    this.markEditingLost(error?.message || this.t('editing.status_failed'));

                    return { ok: false, message: this.editingMessage };
                }
            })();

            runtime.acquisitionPromise = promise;

            try {
                return await promise;
            } finally {
                runtime.acquisitionPromise = null;
            }
        },

        async retryEditing() {
            if (!this.editingRequired || this.editingStale) {
                return null;
            }

            return this.acquireEditingReservation(true);
        },

        async pollEditingState() {
            if (runtime.departing
                || !this.editingRequired
                || !this.editingStarted
                || this.editingStatus === 'acquiring'
                || !isVisibleAndFocused()) {
                return null;
            }

            if (runtime.pollPromise) {
                return runtime.pollPromise;
            }

            const promise = enqueueEditingRead(runtime, async () => {
                if (runtime.departing) {
                    return null;
                }
                const previouslyOwnedLease = this.editingOwnsLease;
                const method = previouslyOwnedLease ? 'heartbeatEditing' : 'editingStatus';

                try {
                    let response = await this.$wire[method]();

                    if (runtime.departing) {
                        this.applyEditingStatusResponse(response, 'poll');

                        return response;
                    }

                    if (!response?.ok && method === 'heartbeatEditing') {
                        this.editingOwnsLease = false;
                        response = await this.$wire.editingStatus();
                    }

                    this.applyEditingStatusResponse(response, 'poll');

                    if (previouslyOwnedLease && this.editingStatus === 'available'
                        && !this.editingStale && this.canEditRecipe && !this.isFormulaLocked) {
                        return this.acquireEditingReservation(false);
                    }

                    return response;
                } catch (error) {
                    this.markEditingLost(error?.message || this.t('editing.status_failed'));

                    return { ok: false, message: this.editingMessage };
                }
            });

            runtime.pollPromise = promise;

            try {
                return await promise;
            } finally {
                runtime.pollPromise = null;
            }
        },

        applyEditingStatusResponse(response, source) {
            if (runtime.departing) {
                if (response?.editing?.status === 'acquired') {
                    this.releaseEditingOnDeparture(response.editing, true);
                }

                return;
            }
            if (!response?.ok || !response.editing) {
                const hasLeaseError = Boolean(response?.errors?.editing_lease);
                this.markEditingLost(response?.message || this.t(hasLeaseError ? 'editing.lease_lost' : 'editing.status_failed'));

                return;
            }

            const editing = response.editing;
            this.editingServerState = editing;

            if (revisionChanged(this, editing)) {
                this.markEditingStale(this.t('editing.stale'), editing);

                return;
            }

            this.editingOwnsLease = editing.status === 'acquired';
            this.editingStatus = editing.status;
            this.editingMessage = '';

            if (source === 'poll' && this.editingStatus === 'inactive') {
                this.editingStatus = 'available';
            }
        },

        recordEditingMutation(response) {
            if (runtime.departing) {
                if (response?.editing?.status === 'acquired') {
                    this.releaseEditingOnDeparture(response.editing, true);
                }

                return response;
            }
            if (!this.editingRequired || !response || typeof response !== 'object') {
                return response;
            }

            if (!response.ok) {
                if (response.errors?.edit_revision) {
                    this.markEditingStale(response.message || this.t('editing.stale'));
                } else if (response.errors?.editing_lease) {
                    this.markEditingLost(response.message || this.t('editing.lease_lost'));
                }

                return response;
            }

            if (response.editing) {
                const editing = response.editing;
                this.editingServerState = editing;
                this.editingRecipeRevision = Number(editing.recipe_revision ?? this.editingRecipeRevision);
                this.editingVersionId = editing.current_version_id ?? null;
                this.editingCostingRevision = Number(editing.costing_revision ?? this.editingCostingRevision);
                this.editingOwnsLease = editing.status === 'acquired';
                this.editingStatus = editing.status;
                this.editingStale = false;
                this.editingMessage = '';
            }

            return response;
        },

        markEditingLost(message) {
            this.editingOwnsLease = false;
            this.editingStatus = 'lost';
            this.editingMessage = message || this.t('editing.lease_lost');
        },

        markEditingStale(message, editing = null) {
            const heldLease = this.editingOwnsLease;
            this.editingOwnsLease = false;
            this.editingStale = true;
            this.editingStatus = 'stale';
            this.editingMessage = message || this.t('editing.stale');

            if (editing) {
                this.editingServerState = editing;
            }

            if ((heldLease || editing?.status === 'acquired') && typeof this.$wire?.releaseEditing === 'function') {
                Promise.resolve(this.$wire.releaseEditing()).catch(() => {});
            }
        },

        async queueRevisionMutation(operation, { allowLocked = false, allowWithoutLease = false } = {}) {
            const run = async () => {
                if (this.editingRequired && this.editingStatus === 'acquiring' && runtime.acquisitionPromise) {
                    await runtime.acquisitionPromise;
                }

                if (runtime.departing || !this.canEditRecipe || (!allowWithoutLease && this.isEditingUnavailable) || (this.isFormulaLocked && !allowLocked)) {
                    return leaseErrorResponse(this);
                }

                try {
                    const response = await operation();
                    this.recordEditingMutation(response);

                    return response;
                } catch (error) {
                    if (this.editingRequired) {
                        this.markEditingLost(error?.message || this.t('editing.status_failed'));
                    }

                    throw error;
                }
            };

            const queued = runtime.mutationQueue.then(run, run);
            runtime.mutationQueue = queued.then(() => undefined, () => undefined);

            return queued;
        },

        async queueEditingRead(operation) {
            return enqueueEditingRead(runtime, operation);
        },

        async waitForRevisionMutationQueue() {
            let queue;

            do {
                queue = runtime.mutationQueue;
                await queue;
            } while (queue !== runtime.mutationQueue);
        },

        async saveRecipeContentWithRevision() {
            return this.queueRevisionMutation(() => this.$wire.saveRecipeContent());
        },

        async submitRecipeControlMutation(event) {
            event.preventDefault();
            const form = event.currentTarget;

            if (!this.canSubmitRecipeControl) {
                return;
            }

            if (await this.flushCostingSave?.() === false) {
                return;
            }

            await this.waitForRevisionMutationQueue();

            if (!this.canSubmitRecipeControl) {
                return;
            }

            const revisionInput = form.querySelector('input[name="expected_revision"]');

            if (revisionInput) {
                revisionInput.value = String(this.editingRecipeRevision);
            }

            form.submit();
        },

        reloadRecipeWithConfirmation() {
            const hasUnsavedChanges = this.blocksNavigation?.() ?? false;

            if (hasUnsavedChanges && !window.confirm(this.t('editing.reload_confirmation'))) {
                return;
            }

            window.location.reload();
        },

        openEditingTakeover() {
            if (!this.canTakeOverEditing) {
                return;
            }

            this.editingTakeoverReason = '';
            this.editingTakeoverOpen = true;
        },

        async confirmEditingTakeover() {
            const reason = this.editingTakeoverReason.trim();

            if (runtime.departing || !this.canTakeOverEditing || reason === '') {
                return;
            }

            if (runtime.pollPromise) {
                await runtime.pollPromise;
            }

            if (runtime.departing) {
                return;
            }

            this.editingStatus = 'acquiring';
            this.editingMessage = '';

            try {
                const response = await this.$wire.takeoverEditing(this.editingToken, reason);
                this.applyEditingStatusResponse(response, 'takeover');
                this.editingTakeoverOpen = false;
                this.editingTakeoverReason = '';
            } catch (error) {
                this.markEditingLost(error?.message || this.t('editing.status_failed'));
            }
        },

        cancelEditingTakeover() {
            this.editingTakeoverOpen = false;
            this.editingTakeoverReason = '';
        },

        handleEditingUpdated(editing) {
            if (!editing) {
                return;
            }

            this.recordEditingMutation({ ok: true, editing });
        },

        releaseEditingOnDeparture(editing = this.editingServerState, repeat = false) {
            if (!runtime.departing || repeat) {
                runtime.resumeOnRestore = this.editingOwnsLease || editing?.status === 'acquired';
            }
            runtime.departing = true;
            this.editingOwnsLease = false;
            this.editingStatus = 'inactive';

            if (!this.editingRequired || !this.editingToken || !editing?.release_url
                || typeof globalThis.fetch !== 'function' || (runtime.releasePromise && !repeat)) {
                return runtime.releasePromise;
            }

            const csrfToken = globalThis.document?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content');

            try {
                const release = globalThis.fetch(editing.release_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken ?? '',
                    },
                    body: JSON.stringify({ token: this.editingToken }),
                }).catch(() => {});
                runtime.releasePromise = Promise.all([runtime.releasePromise, release]);
            } catch {
                // The lease still expires if the browser cannot send a departure request.
            }

            return runtime.releasePromise;
        },

        async restoreEditingProtection() {
            if (!runtime.departing || runtime.destroyed) {
                return;
            }

            await Promise.all([runtime.acquisitionPromise, runtime.pollPromise, runtime.mutationQueue]);
            await runtime.releasePromise;

            if (runtime.destroyed) {
                return;
            }

            runtime.departing = false;
            runtime.releasePromise = null;
            this.editingStatus = 'available';
            await this.pollEditingState();

            if (runtime.resumeOnRestore && this.editingStatus === 'available' && !this.editingStale
                && this.canEditRecipe && !this.isFormulaLocked) {
                await this.acquireEditingReservation(false);
            }
        },

        destroyEditingProtection() {
            runtime.destroyed = true;
            this.releaseEditingOnDeparture();
            if (runtime.pollTimer !== null) {
                globalThis.clearInterval(runtime.pollTimer);
                runtime.pollTimer = null;
            }

            if (runtime.focusHandler && typeof window !== 'undefined') {
                window.removeEventListener?.('focus', runtime.focusHandler);
                runtime.focusHandler = null;
            }

            if (runtime.visibilityHandler && typeof document !== 'undefined') {
                document.removeEventListener?.('visibilitychange', runtime.visibilityHandler);
                runtime.visibilityHandler = null;
            }

            if (runtime.departureHandler) {
                globalThis.window?.removeEventListener?.('pagehide', runtime.departureHandler);
                globalThis.document?.removeEventListener?.('livewire:navigating', runtime.departureHandler);
                runtime.departureHandler = null;
            }

            if (runtime.restoreHandler) {
                globalThis.window?.removeEventListener?.('pageshow', runtime.restoreHandler);
                runtime.restoreHandler = null;
            }
        },
    };
}
