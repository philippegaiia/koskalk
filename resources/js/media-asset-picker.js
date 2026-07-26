export function createMediaAssetPicker(options) {
    return {
        open: options.embedded,
        embedded: options.embedded,
        activeTab: 'library',
        search: '',
        assets: [],
        assetsPage: 1,
        hasMoreAssets: false,
        assetsLoading: false,
        assetsGeneration: 0,
        assetsError: null,
        assetsUrl: options.assetsUrl,
        livewire: options.livewire,
        statePath: options.statePath,
        state: options.state,
        multiple: options.multiple,
        maximumItems: options.maximumItems,
        preserveAspectRatio: options.preserveAspectRatio,
        messages: options.messages,
        pendingUpload: null,
        pollTimer: null,
        pollFailures: 0,
        opener: null,
        uploadSubmitting: false,
        uploadProgress: 0,
        uploadError: null,
        uploadFilename: '',

        init() {
            if (this.embedded) {
                this.loadAssets(true);
            }
        },

        destroy() {
            window.clearTimeout(this.pollTimer);
        },

        async openPicker() {
            this.opener = this.$refs.trigger;
            this.open = true;
            this.activeTab = 'library';

            if (this.assets.length === 0) {
                await this.loadAssets(true);
            }

            this.$nextTick(() => this.$refs.search?.focus());
        },

        closePicker() {
            this.open = false;
            this.$nextTick(() => this.opener?.focus());
        },

        async loadAssets(reset = false) {
            if (this.assetsLoading && !reset) {
                return;
            }

            const generation = reset ? ++this.assetsGeneration : this.assetsGeneration;
            this.assetsLoading = true;

            if (reset) {
                this.assetsPage = 1;
                this.assets = [];
                this.assetsError = null;
            }

            try {
                const url = new URL(this.assetsUrl, window.location.origin);
                url.searchParams.set('page', this.assetsPage);

                if (this.search.trim()) {
                    url.searchParams.set('search', this.search.trim());
                }

                const result = await this.request(url.toString(), 'GET');

                if (generation !== this.assetsGeneration) {
                    return;
                }

                this.assets.push(...result.data);
                this.hasMoreAssets = result.has_more;
                this.assetsPage = result.next_page ?? this.assetsPage;
                this.assetsError = null;
            } catch (error) {
                if (generation !== this.assetsGeneration) {
                    return;
                }

                this.assetsError = error.message;
            } finally {
                if (generation === this.assetsGeneration) {
                    this.assetsLoading = false;
                }
            }
        },

        retryAssets() {
            this.loadAssets(true);
        },

        loadMoreAssets() {
            if (this.hasMoreAssets) {
                this.loadAssets();
            }
        },

        select(id, closeSingle = true) {
            if (!this.multiple) {
                this.state = id;

                if (closeSingle && !this.embedded) {
                    this.closePicker();
                } else {
                    this.open = true;
                }

                return;
            }

            const values = Array.isArray(this.state) ? [...this.state] : [];
            const index = values.map(Number).indexOf(Number(id));

            if (index >= 0) {
                values.splice(index, 1);
            } else if (values.length < this.maximumItems) {
                values.push(id);
            }

            this.state = values;
        },

        selected(id) {
            return this.multiple
                ? (Array.isArray(this.state) && this.state.map(Number).includes(Number(id)))
                : Number(this.state) === Number(id);
        },

        focusTab(tab) {
            this.activeTab = tab;
            this.$nextTick(() => this.$refs[`${tab}Tab`]?.focus());
        },

        moveTabFocus() {
            this.focusTab(this.activeTab === 'library' ? 'upload' : 'library');
        },

        selectUploadFile(event) {
            this.uploadFilename = event.target.files?.[0]?.name ?? '';
        },

        clearUploadFile() {
            this.uploadFilename = '';

            if (this.$refs.uploadInput) {
                this.$refs.uploadInput.value = '';
            }
        },

        trackUpload(detail) {
            if (detail.statePath !== this.statePath) {
                return;
            }

            this.open = true;
            this.activeTab = 'library';
            this.pendingUpload = {
                id: Number(detail.assetId),
                statusUrl: detail.statusUrl,
                status: 'processing',
                progress: 0,
                failureReason: null,
                retryUrl: null,
                removeUrl: null,
            };
            this.pollFailures = 0;
            this.$nextTick(() => this.$refs.libraryTab?.focus());
            this.pollUpload();
        },

        uploadNew() {
            const input = this.$refs.uploadInput;
            const file = input?.files?.[0];

            if (!file || this.uploadSubmitting) {
                return;
            }

            this.uploadSubmitting = true;
            this.uploadProgress = 0;
            this.uploadError = null;

            const uploadProperty = `componentFileAttachments.${this.statePath}.mediaPickerUpload`;

            this.livewire.upload(
                uploadProperty,
                file,
                async () => {
                    try {
                        const result = await this.livewire.startMediaAssetPickerUpload(this.statePath);

                        if (result.error) {
                            throw new Error(result.error);
                        }

                        this.clearUploadFile();
                        this.trackUpload({
                            statePath: this.statePath,
                            assetId: result.asset_id,
                            statusUrl: result.status_url,
                        });
                    } catch (error) {
                        this.uploadError = error.message ?? this.messages.uploadFailed;
                    } finally {
                        this.uploadSubmitting = false;
                    }
                },
                () => {
                    this.uploadError = this.messages.uploadFailed;
                    this.uploadSubmitting = false;
                },
                (event) => {
                    this.uploadProgress = event.detail.progress;
                },
                () => {
                    this.uploadError = this.messages.uploadFailed;
                    this.uploadSubmitting = false;
                },
            );
        },

        async request(url, method) {
            let response;

            try {
                response = await fetch(url, {
                    method,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    credentials: 'same-origin',
                });
            } catch (cause) {
                throw Object.assign(new Error(this.messages.refreshFailed), { status: 0 });
            }

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw Object.assign(
                    new Error(
                        result.errors?.plan?.[0]
                        ?? result.message
                        ?? this.messages.refreshFailed,
                    ),
                    { status: response.status },
                );
            }

            return result;
        },

        async retryUpload() {
            if (!this.pendingUpload?.retryUrl) {
                return;
            }

            try {
                const result = await this.request(this.pendingUpload.retryUrl, 'POST');
                this.pendingUpload = {
                    ...this.pendingUpload,
                    status: result.status,
                    progress: result.progress ?? 0,
                    failureReason: result.failure_reason,
                    retryUrl: null,
                    removeUrl: null,
                };
                this.pollFailures = 0;
                this.pollUpload();
            } catch (error) {
                this.pendingUpload.failureReason = error.message;
            }
        },

        async removeUpload() {
            if (!this.pendingUpload?.removeUrl) {
                return;
            }

            try {
                await this.request(this.pendingUpload.removeUrl, 'DELETE');
                this.pendingUpload = null;
                this.search = '';
            } catch (error) {
                this.pendingUpload.failureReason = error.message;
            }
        },

        async pollUpload() {
            window.clearTimeout(this.pollTimer);

            if (!this.pendingUpload) {
                return;
            }

            try {
                const result = await this.request(this.pendingUpload.statusUrl, 'GET');
                this.pollFailures = 0;
                this.pendingUpload = {
                    ...this.pendingUpload,
                    status: result.status,
                    progress: result.progress ?? 0,
                    failureReason: result.failure_reason,
                    retryUrl: result.retry_url,
                    removeUrl: result.remove_url,
                };

                if (result.status === 'ready') {
                    this.select(this.pendingUpload.id, false);
                    this.pendingUpload = null;
                    await this.loadAssets(true);

                    return;
                }

                if (result.status === 'failed') {
                    return;
                }
            } catch (error) {
                this.pendingUpload.failureReason = error.message;

                if ([401, 403, 404, 419].includes(error.status)) {
                    return;
                }

                this.pollFailures++;

                if (this.pollFailures >= 3) {
                    this.pendingUpload.failureReason = this.messages.pollingStopped;

                    return;
                }
            }

            this.pollTimer = window.setTimeout(
                () => this.pollUpload(),
                2000 * (2 ** this.pollFailures),
            );
        },
    };
}
