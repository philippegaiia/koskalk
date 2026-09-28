export function buildListOutputUrl({ endpoint, origin, parameters }) {
    const url = new URL(endpoint, origin);

    for (const [key, value] of Object.entries(parameters)) {
        url.searchParams.set(key, value ?? '');
    }

    return url.toString();
}

export function isListOutputEmptyForVisibleSearch({ hasResults, visibleSearch, serverSearch }) {
    return ! hasResults && visibleSearch === serverSearch;
}

function filenameFromContentDisposition(header, fallback) {
    const encodedName = header?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];

    if (encodedName) {
        try {
            return decodeURIComponent(encodedName.replace(/^"|"$/g, ''));
        } catch (error) {
            void error;
        }
    }

    const plainName = header?.match(/filename="?([^";]+)"?/i)?.[1];

    return plainName || fallback;
}

async function responseError(response, messages) {
    try {
        const body = await response.json();
        const validationMessage = Object.values(body?.errors ?? {})
            .flat()
            .find((message) => typeof message === 'string' && message !== '');

        if (validationMessage) {
            return validationMessage;
        }
    } catch (error) {
        void error;
    }

    const messageKey = ({
        401: 'access',
        403: 'forbidden',
        409: 'workspaceChanged',
        422: 'invalid',
        429: 'throttled',
    })[response.status] ?? 'failed';

    return messages[messageKey] || messages.failed;
}

function downloadBlob(blob, filename, document, URLApi, schedule) {
    const objectUrl = URLApi.createObjectURL(blob);
    let anchor = null;

    try {
        anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = filename;
        anchor.hidden = true;
        document.body.append(anchor);
        anchor.click();
    } finally {
        anchor?.remove();
        schedule(() => URLApi.revokeObjectURL(objectUrl), 1000);
    }
}

export function createListOutputDownload(dependencies = {}) {
    const document = dependencies.document ?? globalThis.document;
    const window = dependencies.window ?? globalThis.window;
    const fetch = dependencies.fetch ?? globalThis.fetch;
    const URLApi = dependencies.URL ?? window?.URL ?? globalThis.URL;
    const schedule = dependencies.setTimeout ?? globalThis.setTimeout;
    const MutationObserver = dependencies.MutationObserver ?? globalThis.MutationObserver;
    let serverResultsObserver = null;

    return {
        outputPending: false,
        outputError: '',
        outputMenuOpen: false,
        visibleSearch: '',
        serverHasResults: false,
        serverSearch: '',

        init() {
            this.visibleSearch = this.searchInput()?.value ?? '';
            this.syncServerResults();

            if (typeof MutationObserver === 'function') {
                serverResultsObserver = new MutationObserver(() => this.syncServerResults());
                serverResultsObserver.observe(this.$el, {
                    attributes: true,
                    attributeFilter: ['data-export-has-results', 'data-export-server-search'],
                });
            }
        },

        destroy() {
            serverResultsObserver?.disconnect();
        },

        syncServerResults() {
            this.serverHasResults = this.$el.dataset.exportHasResults === 'true';
            this.serverSearch = this.$el.dataset.exportServerSearch ?? '';
        },

        searchInput() {
            return this.$el.querySelector('[data-list-output-search]');
        },

        currentResultsAreEmpty() {
            return isListOutputEmptyForVisibleSearch({
                hasResults: this.serverHasResults,
                visibleSearch: this.visibleSearch,
                serverSearch: this.serverSearch,
            });
        },

        toggleMenu() {
            if (this.outputPending || this.currentResultsAreEmpty()) {
                return false;
            }

            this.outputMenuOpen = ! this.outputMenuOpen;

            return this.outputMenuOpen;
        },

        async exportList(format) {
            if (this.outputPending || this.currentResultsAreEmpty()) {
                return false;
            }

            this.outputPending = true;
            this.outputError = '';

            try {
                const parameters = JSON.parse(this.$el.dataset.exportParameters ?? '{}');
                parameters.format = format;
                parameters[this.$el.dataset.exportSearchParameter ?? 'search'] = this.searchInput()?.value ?? '';
                const url = buildListOutputUrl({
                    endpoint: this.$el.dataset.exportUrl,
                    origin: window.location.origin,
                    parameters,
                });
                const response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (! response.ok) {
                    throw new Error(await responseError(response, {
                        access: this.$el.dataset.exportErrorAccess,
                        forbidden: this.$el.dataset.exportErrorForbidden,
                        workspaceChanged: this.$el.dataset.exportErrorWorkspaceChanged,
                        invalid: this.$el.dataset.exportErrorInvalid,
                        throttled: this.$el.dataset.exportErrorThrottled,
                        failed: this.$el.dataset.exportErrorFailed,
                    }));
                }

                const fallback = this.$el.dataset.exportFilenameFallback?.replace('{format}', format) ?? `ingredients.${format}`;
                const filename = filenameFromContentDisposition(response.headers.get('Content-Disposition'), fallback);
                downloadBlob(await response.blob(), filename, document, URLApi, schedule);

                return true;
            } catch (error) {
                this.outputError = error instanceof Error && error.message
                    ? error.message
                    : this.$el.dataset.exportErrorFailed;

                return false;
            } finally {
                this.outputPending = false;
            }
        },
    };
}
