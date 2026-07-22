const DEFAULT_SAVE_INTERVAL = 120_000;
const DEFAULT_REGISTRY_KEY = 'recipe-content';

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
        registry: options.registry ?? null,
        registryKey: options.registryKey ?? DEFAULT_REGISTRY_KEY,
        saveInterval: options.interval ?? DEFAULT_SAVE_INTERVAL,
        saveCallback: options.save ?? (async () => ({ ok: false, message: labels.saveFailed })),
        savedAtFormatter: options.formatSavedAt ?? defaultSavedAtFormatter,
        clock,
        labels,
        changeSequence: 0,
        isInitialized: false,
        isDestroyed: false,
        inputHandler: null,
        uploadStartHandler: null,
        uploadFinishHandler: null,
        uploadErrorHandler: null,
        uploadCancelHandler: null,

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
            this.eventTarget ??= this.$el ?? null;
            this.updateRegistry();

            if (!this.eventTarget) {
                return;
            }

            this.inputHandler = () => this.markDirty();
            this.uploadStartHandler = () => this.uploadStarted();
            this.uploadFinishHandler = () => this.uploadFinished();
            this.uploadErrorHandler = () => this.uploadErrored();
            this.uploadCancelHandler = () => this.uploadCancelled();

            this.eventTarget.addEventListener('input', this.inputHandler, true);
            this.eventTarget.addEventListener('change', this.inputHandler, true);
            this.eventTarget.addEventListener('livewire-upload-start', this.uploadStartHandler);
            this.eventTarget.addEventListener('livewire-upload-finish', this.uploadFinishHandler);
            this.eventTarget.addEventListener('livewire-upload-error', this.uploadErrorHandler);
            this.eventTarget.addEventListener('livewire-upload-cancel', this.uploadCancelHandler);
        },

        destroy() {
            this.isDestroyed = true;
            this.clearSaveTimer();

            if (this.isInitialized && this.eventTarget) {
                this.eventTarget.removeEventListener('input', this.inputHandler, true);
                this.eventTarget.removeEventListener('change', this.inputHandler, true);
                this.eventTarget.removeEventListener('livewire-upload-start', this.uploadStartHandler);
                this.eventTarget.removeEventListener('livewire-upload-finish', this.uploadFinishHandler);
                this.eventTarget.removeEventListener('livewire-upload-error', this.uploadErrorHandler);
                this.eventTarget.removeEventListener('livewire-upload-cancel', this.uploadCancelHandler);
            }

            this.isInitialized = false;
            this.registry?.remove(this.registryKey);
        },

        markDirty() {
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
            this.activeUploads += 1;
            this.markDirty();
        },

        uploadFinished() {
            this.finishUpload();
        },

        uploadErrored() {
            this.activeUploads = Math.max(0, this.activeUploads - 1);
            this.errorMessage = this.labels.saveFailed;
            this.clearSaveTimer();
            this.setState('failed');
        },

        uploadCancelled() {
            this.finishUpload();
        },

        finishUpload() {
            this.activeUploads = Math.max(0, this.activeUploads - 1);

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
            if (this.inFlight) {
                return this.inFlight;
            }

            if (this.activeUploads > 0) {
                return Promise.resolve(null);
            }

            const savedSequence = this.changeSequence;
            this.clearSaveTimer();
            this.errorMessage = '';
            this.setState('saving');

            let response;

            try {
                response = this.saveCallback();
            } catch (error) {
                response = Promise.reject(error);
            }

            this.inFlight = Promise.resolve(response)
                .then((result) => {
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
                    this.errorMessage = error?.message || this.labels.saveFailed;
                    this.setState('failed');

                    return { ok: false, message: this.errorMessage };
                })
                .finally(() => {
                    this.inFlight = null;

                    if (this.state === 'dirty') {
                        this.scheduleSaveTimer();
                    }
                });

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
