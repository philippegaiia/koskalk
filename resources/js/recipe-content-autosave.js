const DEFAULT_SAVE_INTERVAL = 120_000;
const DEFAULT_UPLOAD_SAFETY_INTERVAL = 600_000;
const DEFAULT_REGISTRY_KEY = 'recipe-content';
const DEFAULT_WATCH_PATHS = [
    'data.description',
    'data.manufacturing_instructions',
];

function browserClock() {
    return {
        now: () => Date.now(),
        setTimeout: (callback, delay) => globalThis.setTimeout(callback, delay),
        clearTimeout: (timer) => globalThis.clearTimeout(timer),
    };
}

function defaultSavedAtFormatter(value) {
    const savedAt = new Date(value);

    if (Number.isNaN(savedAt.getTime())) {
        return '';
    }

    return savedAt.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function createRecipeContentAutosave(options = {}) {
    const clock = options.clock ?? browserClock();
    const labels = {
        allSaved: 'All changes saved',
        unsaved: 'Unsaved changes',
        saving: 'Saving…',
        savedAt: 'Saved at :time',
        saveFailed: 'Save failed',
        ...(options.labels ?? {}),
    };

    return {
        state: 'saved',
        dirtySince: null,
        saveDeadline: null,
        activeUploads: 0,
        timer: null,
        inFlight: null,
        savedAt: null,
        errorMessage: '',
        eventTarget: options.eventTarget ?? null,
        uploadEventTarget: options.uploadEventTarget ?? (typeof window === 'undefined' ? null : window),
        livewireId: options.livewireId ?? null,
        watchCallback: options.watch ?? null,
        watchPaths: options.watchPaths ?? DEFAULT_WATCH_PATHS,
        registry: options.registry ?? null,
        registryKey: options.registryKey ?? DEFAULT_REGISTRY_KEY,
        saveInterval: options.interval ?? DEFAULT_SAVE_INTERVAL,
        uploadSafetyInterval: options.uploadSafetyInterval ?? DEFAULT_UPLOAD_SAFETY_INTERVAL,
        saveCallback: options.save ?? (async () => ({ ok: false, message: labels.saveFailed })),
        savedAtFormatter: options.formatSavedAt ?? defaultSavedAtFormatter,
        clock,
        labels,
        changeSequence: 0,
        lifecycleVersion: 0,
        isInitialized: false,
        isDestroyed: false,
        nativeUploads: 0,
        richUploads: new Map(),
        formProcessingDepth: 0,
        richUploadSafetyTimer: null,
        unwatchCallbacks: [],
        inputHandler: null,
        uploadStartHandler: null,
        uploadFinishHandler: null,
        uploadErrorHandler: null,
        uploadCancelHandler: null,
        richUploadStartHandler: null,
        richUploadFinishHandler: null,
        richUploadValidationHandler: null,
        formProcessingStartHandler: null,
        formProcessingFinishHandler: null,

        get statusText() {
            if (this.state === 'saving') {
                return this.labels.saving;
            }

            if (this.state === 'failed') {
                return this.errorMessage || this.labels.saveFailed;
            }

            if (this.state === 'dirty') {
                return this.labels.unsaved;
            }

            if (this.savedAt) {
                return this.labels.savedAt.replace(':time', this.savedAt);
            }

            return this.labels.allSaved;
        },

        get saveDisabled() {
            return this.state === 'saving' || this.activeUploads > 0;
        },

        init() {
            if (this.isInitialized) {
                return;
            }

            this.isInitialized = true;
            this.isDestroyed = false;
            this.lifecycleVersion += 1;
            this.eventTarget ??= this.$el ?? null;
            this.updateRegistry();

            if (this.watchCallback) {
                this.unwatchCallbacks = this.watchPaths
                    .map((path) => this.watchCallback(path, () => this.markDirty()))
                    .filter((unwatch) => typeof unwatch === 'function');
            }

            if (!this.eventTarget) {
                return;
            }

            this.inputHandler = () => this.markDirty();
            this.uploadStartHandler = () => this.uploadStarted();
            this.uploadFinishHandler = () => this.uploadFinished();
            this.uploadErrorHandler = () => this.uploadErrored();
            this.uploadCancelHandler = () => this.uploadCancelled();
            this.formProcessingStartHandler = () => {
                this.formProcessingDepth += 1;
            };
            this.formProcessingFinishHandler = () => this.formProcessingFinished();
            this.richUploadStartHandler = (event) => {
                if (this.isMatchingRichEditorEvent(event)) {
                    this.richUploadStarted(event.detail.key);
                }
            };
            this.richUploadFinishHandler = (event) => {
                if (this.isMatchingRichEditorEvent(event)) {
                    this.richUploadFinished(event.detail.key);
                }
            };
            this.richUploadValidationHandler = (event) => {
                if (this.isMatchingRichEditorEvent(event)) {
                    this.richUploadFinished(event.detail.key);
                }
            };

            this.eventTarget.addEventListener('input', this.inputHandler, true);
            this.eventTarget.addEventListener('change', this.inputHandler, true);
            this.eventTarget.addEventListener('livewire-upload-start', this.uploadStartHandler);
            this.eventTarget.addEventListener('livewire-upload-finish', this.uploadFinishHandler);
            this.eventTarget.addEventListener('livewire-upload-error', this.uploadErrorHandler);
            this.eventTarget.addEventListener('livewire-upload-cancel', this.uploadCancelHandler);
            this.eventTarget.addEventListener('form-processing-started', this.formProcessingStartHandler);
            this.eventTarget.addEventListener('form-processing-finished', this.formProcessingFinishHandler);
            this.uploadEventTarget?.addEventListener('rich-editor-uploading-file', this.richUploadStartHandler);
            this.uploadEventTarget?.addEventListener('rich-editor-uploaded-file', this.richUploadFinishHandler);
            this.uploadEventTarget?.addEventListener('rich-editor-file-validation-message', this.richUploadValidationHandler);
        },

        destroy() {
            this.isDestroyed = true;
            this.lifecycleVersion += 1;
            this.clearSaveTimer();
            this.clearRichUploadSafetyTimer();
            this.inFlight = null;

            if (this.isInitialized && this.eventTarget) {
                this.eventTarget.removeEventListener('input', this.inputHandler, true);
                this.eventTarget.removeEventListener('change', this.inputHandler, true);
                this.eventTarget.removeEventListener('livewire-upload-start', this.uploadStartHandler);
                this.eventTarget.removeEventListener('livewire-upload-finish', this.uploadFinishHandler);
                this.eventTarget.removeEventListener('livewire-upload-error', this.uploadErrorHandler);
                this.eventTarget.removeEventListener('livewire-upload-cancel', this.uploadCancelHandler);
                this.eventTarget.removeEventListener('form-processing-started', this.formProcessingStartHandler);
                this.eventTarget.removeEventListener('form-processing-finished', this.formProcessingFinishHandler);
            }

            if (this.isInitialized && this.uploadEventTarget) {
                this.uploadEventTarget.removeEventListener('rich-editor-uploading-file', this.richUploadStartHandler);
                this.uploadEventTarget.removeEventListener('rich-editor-uploaded-file', this.richUploadFinishHandler);
                this.uploadEventTarget.removeEventListener('rich-editor-file-validation-message', this.richUploadValidationHandler);
            }

            for (const unwatch of this.unwatchCallbacks) {
                unwatch();
            }

            this.unwatchCallbacks = [];
            this.nativeUploads = 0;
            this.richUploads.clear();
            this.formProcessingDepth = 0;
            this.activeUploads = 0;

            this.isInitialized = false;
            this.registry?.remove(this.registryKey);
        },

        markDirty() {
            if (this.isDestroyed) {
                return;
            }

            this.changeSequence += 1;

            if (this.dirtySince === null) {
                this.dirtySince = this.clock.now();
                this.saveDeadline = this.dirtySince + this.saveInterval;
            }

            if (this.state !== 'saving' && this.state !== 'failed') {
                this.setState('dirty');
            }

            if (this.state === 'dirty') {
                this.scheduleSaveTimer();
            }
        },

        uploadStarted() {
            if (this.isDestroyed) {
                return;
            }

            this.nativeUploads += 1;
            this.syncActiveUploads();
            this.markDirty();
        },

        uploadFinished() {
            this.finishUpload();
        },

        uploadErrored() {
            if (this.isDestroyed) {
                return;
            }

            this.nativeUploads = Math.max(0, this.nativeUploads - 1);
            this.syncActiveUploads();
            this.errorMessage = this.labels.saveFailed;
            this.clearSaveTimer();
            this.setState('failed');
        },

        uploadCancelled() {
            this.finishUpload();
        },

        finishUpload() {
            if (this.isDestroyed) {
                return;
            }

            this.nativeUploads = Math.max(0, this.nativeUploads - 1);
            this.syncActiveUploads();
            this.saveOverdueChangesAfterUploads();
        },

        isMatchingRichEditorEvent(event) {
            return event?.detail?.livewireId === this.livewireId
                && typeof event.detail.key === 'string';
        },

        richUploadStarted(key) {
            if (this.isDestroyed) {
                return;
            }

            this.richUploads.set(key, (this.richUploads.get(key) ?? 0) + 1);
            this.syncActiveUploads();
            this.scheduleRichUploadSafetyTimer();
            this.markDirty();
        },

        richUploadFinished(key) {
            if (this.isDestroyed) {
                return;
            }

            const remainingUploads = Math.max(0, (this.richUploads.get(key) ?? 0) - 1);

            if (remainingUploads === 0) {
                this.richUploads.delete(key);
            } else {
                this.richUploads.set(key, remainingUploads);
            }

            if (this.richUploads.size === 0) {
                this.clearRichUploadSafetyTimer();
            }

            this.syncActiveUploads();
            this.saveOverdueChangesAfterUploads();
        },

        formProcessingFinished() {
            if (this.isDestroyed) {
                return;
            }

            this.formProcessingDepth = Math.max(0, this.formProcessingDepth - 1);

            if (this.formProcessingDepth === 0 && this.richUploads.size === 0) {
                this.clearRichUploadSafetyTimer();
            }

            this.saveOverdueChangesAfterUploads();
        },

        syncActiveUploads() {
            const richUploadCount = [...this.richUploads.values()]
                .reduce((total, count) => total + count, 0);

            this.activeUploads = this.nativeUploads + richUploadCount;
        },

        scheduleRichUploadSafetyTimer() {
            this.clearRichUploadSafetyTimer();
            this.richUploadSafetyTimer = this.clock.setTimeout(() => {
                this.richUploadSafetyTimer = null;

                if (this.isDestroyed || this.richUploads.size === 0) {
                    return;
                }

                this.richUploads.clear();
                this.formProcessingDepth = 0;
                this.syncActiveUploads();
                this.errorMessage = this.labels.saveFailed;
                this.clearSaveTimer();
                this.setState('failed');
            }, this.uploadSafetyInterval);
        },

        clearRichUploadSafetyTimer() {
            if (this.richUploadSafetyTimer === null) {
                return;
            }

            this.clock.clearTimeout(this.richUploadSafetyTimer);
            this.richUploadSafetyTimer = null;
        },

        saveOverdueChangesAfterUploads() {
            if (this.activeUploads === 0
                && this.state === 'dirty'
                && this.saveDeadline !== null
                && this.clock.now() >= this.saveDeadline) {
                this.save();
            }
        },

        scheduleSaveTimer() {
            if (this.timer !== null || this.saveDeadline === null || this.state !== 'dirty') {
                return;
            }

            const delay = Math.max(0, this.saveDeadline - this.clock.now());
            this.timer = this.clock.setTimeout(() => {
                this.timer = null;

                if (this.activeUploads > 0) {
                    return;
                }

                this.save();
            }, delay);
        },

        clearSaveTimer() {
            if (this.timer === null) {
                return;
            }

            this.clock.clearTimeout(this.timer);
            this.timer = null;
        },

        save() {
            if (this.isDestroyed) {
                return Promise.resolve(null);
            }

            if (this.inFlight) {
                return this.inFlight;
            }

            if (this.activeUploads > 0) {
                return Promise.resolve(null);
            }

            const savedSequence = this.changeSequence;
            const saveLifecycleVersion = this.lifecycleVersion;
            this.clearSaveTimer();
            this.errorMessage = '';
            this.setState('saving');

            let response;

            try {
                response = this.saveCallback();
            } catch (error) {
                response = Promise.reject(error);
            }

            const savePromise = Promise.resolve(response)
                .then((result) => {
                    if (this.isDestroyed || this.lifecycleVersion !== saveLifecycleVersion) {
                        return result;
                    }

                    if (!result?.ok) {
                        this.errorMessage = result?.message ?? this.labels.saveFailed;
                        this.setState('failed');

                        return result;
                    }

                    if (this.changeSequence !== savedSequence) {
                        this.setState('dirty');

                        return result;
                    }

                    this.dirtySince = null;
                    this.saveDeadline = null;
                    this.savedAt = this.savedAtFormatter(result.saved_at ?? this.clock.now());
                    this.errorMessage = '';
                    this.setState('saved');

                    return result;
                })
                .catch((error) => {
                    if (this.isDestroyed || this.lifecycleVersion !== saveLifecycleVersion) {
                        return { ok: false, message: error?.message || this.labels.saveFailed };
                    }

                    this.errorMessage = error?.message || this.labels.saveFailed;
                    this.setState('failed');

                    return { ok: false, message: this.errorMessage };
                })
                .finally(() => {
                    if (this.inFlight === savePromise) {
                        this.inFlight = null;
                    }

                    if (this.isDestroyed || this.lifecycleVersion !== saveLifecycleVersion) {
                        return;
                    }

                    if (this.state === 'dirty') {
                        this.scheduleSaveTimer();
                    }
                });

            this.inFlight = savePromise;

            return this.inFlight;
        },

        setState(state) {
            this.state = state;
            this.updateRegistry();
        },

        updateRegistry() {
            if (!this.isDestroyed) {
                this.registry?.set(this.registryKey, this.state);
            }
        },
    };
}
