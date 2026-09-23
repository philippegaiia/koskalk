export function createMediaLibraryUploader(options) {
    return {
        files: [],
        uploading: false,
        completedCount: 0,
        failedCount: 0,
        currentIndex: 0,
        currentProgress: 0,
        livewire: options.livewire,
        maxFiles: options.maxFiles,
        remaining: options.remaining,
        messages: options.messages,

        get overBatchLimit() {
            return this.files.length > this.maxFiles;
        },

        get overQuotaLimit() {
            return this.remaining !== null && this.files.length > this.remaining;
        },

        get batchOverflow() {
            return Math.max(0, this.files.length - this.maxFiles);
        },

        get canUpload() {
            return this.files.length > 0
                && !this.uploading
                && !this.overBatchLimit
                && !this.overQuotaLimit;
        },

        selectFiles(event) {
            this.completedCount = 0;
            this.failedCount = 0;
            this.files = Array.from(event.target.files ?? []).map((file, index) => ({
                id: `${file.name}-${file.size ?? 0}-${file.lastModified ?? 0}-${index}`,
                file,
                name: file.name,
                status: 'selected',
                progress: 0,
                error: null,
            }));
            event.target.value = '';
        },

        removeFile(index) {
            if (!this.uploading) {
                this.files.splice(index, 1);
                this.failedCount = this.files.filter((entry) => entry.status === 'failed').length;
            }
        },

        async uploadBatch() {
            if (!this.canUpload) {
                return;
            }

            this.uploading = true;
            this.completedCount = 0;
            this.failedCount = 0;

            try {
                for (const [index, entry] of this.files.entries()) {
                    this.currentIndex = index;
                    this.currentProgress = 0;
                    await this.uploadFile(entry);
                }
            } finally {
                this.uploading = false;
                this.completedCount = this.files.filter((entry) => entry.status === 'queued').length;
                this.files = this.files.filter((entry) => entry.status === 'failed');
                this.failedCount = this.files.length;
                if (this.remaining !== null) {
                    this.remaining = Math.max(0, this.remaining - this.completedCount);
                }
            }
        },

        uploadFile(entry) {
            entry.status = 'uploading';
            entry.error = null;

            return new Promise((resolve) => {
                try {
                    this.livewire.upload(
                        'upload',
                        entry.file,
                        async () => {
                            try {
                                if (await this.livewire.uploadAsset() !== true) {
                                    throw new Error('Upload was not accepted');
                                }
                                entry.status = 'queued';
                            } catch {
                                this.failEntry(entry);
                            }

                            resolve();
                        },
                        () => {
                            this.failEntry(entry);
                            resolve();
                        },
                        (event) => {
                            entry.progress = event.detail.progress;
                            this.currentProgress = event.detail.progress;
                        },
                        () => {
                            this.failEntry(entry);
                            resolve();
                        },
                    );
                } catch {
                    this.failEntry(entry);
                    resolve();
                }
            });
        },

        failEntry(entry) {
            entry.status = 'failed';
            entry.error = this.message('uploadFailed', { name: entry.name });
        },

        message(key, replacements = {}) {
            return Object.entries(replacements).reduce(
                (message, [name, value]) => message.replace(`:${name}`, value),
                this.messages[key] ?? '',
            );
        },

        choiceMessage(key, count) {
            const choices = (this.messages[key] ?? '').split('|');
            const choice = count === 1 ? choices[0] : (choices[1] ?? choices[0]);

            return choice
                .replace(/^(?:\{[^}]+}|\[[^\]]+])\s*/, '')
                .replace(':count', count);
        },
    };
}
