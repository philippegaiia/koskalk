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
    let root = null;
    // Inside an event handler Alpine resolves this.$el to the clicked element,
    // so the component root (which carries the data attributes) is kept from init().
    const outputRoot = (component) => root ?? component.$el;

    return {
        outputPending: false,
        outputError: '',
        outputMenuOpen: false,
        visibleSearch: '',
        serverHasResults: false,
        serverSearch: '',
        serverParameters: {},

        init() {
            root = this.$el;
            this.visibleSearch = this.searchInput()?.value ?? '';
            this.syncServerResults();

            if (typeof MutationObserver === 'function') {
                serverResultsObserver = new MutationObserver(() => this.syncServerResults());
                serverResultsObserver.observe(outputRoot(this), {
                    attributes: true,
                    attributeFilter: ['data-export-has-results', 'data-export-server-search', 'data-export-parameters'],
                });
            }
        },

        destroy() {
            serverResultsObserver?.disconnect();
        },

        syncServerResults() {
            this.serverHasResults = outputRoot(this).dataset.exportHasResults === 'true';
            this.serverSearch = outputRoot(this).dataset.exportServerSearch ?? '';
            this.serverParameters = JSON.parse(outputRoot(this).dataset.exportParameters ?? '{}');
        },

        searchInput() {
            return outputRoot(this).querySelector(outputRoot(this).dataset.exportSearchSelector ?? '[data-list-output-search]');
        },

        captureParameters() {
            const parameters = { ...this.serverParameters };
            for (const key of JSON.parse(outputRoot(this).dataset.exportLivewireFilters ?? '[]')) {
                parameters[key] = this.$wire.$get(key);
            }
            if (outputRoot(this).dataset.exportMaterialSelection) {
                const selection = this.$wire.$get(outputRoot(this).dataset.exportMaterialSelection) ?? '';
                const separator = selection.indexOf(':');
                parameters.lotMaterialType = separator < 0 ? '' : selection.slice(0, separator);
                parameters.lotMaterial = separator < 0 ? '' : selection.slice(separator + 1);
            }
            parameters[outputRoot(this).dataset.exportSearchParameter ?? 'search'] = this.searchInput()?.value ?? '';

            return parameters;
        },

        currentResultsAreEmpty() {
            const current = this.captureParameters();
            for (const key of JSON.parse(outputRoot(this).dataset.exportLivewireFilters ?? '[]')) {
                if ((current[key] ?? '') !== (this.serverParameters[key] ?? '')) {
                    return false;
                }
            }
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

        async printList() {
            if (this.outputPending || this.currentResultsAreEmpty()) {
                return false;
            }
            const popup = window.open('', '_blank');
            this.outputError = '';
            if (! popup) {
                this.outputError = outputRoot(this).dataset.exportErrorPopup;
                return false;
            }
            this.outputPending = true;
            try {
                popup.document.title = outputRoot(this).dataset.exportPreparing;
                const message = popup.document.createElement('p');
                message.textContent = outputRoot(this).dataset.exportPreparing;
                popup.document.body.replaceChildren(message);
                const url = buildListOutputUrl({ endpoint: outputRoot(this).dataset.printUrl, origin: window.location.origin, parameters: this.captureParameters() });
                const response = await fetch(url, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (! response.ok) {
                    throw new Error(await responseError(response, {
                        access: outputRoot(this).dataset.exportErrorAccess,
                        forbidden: outputRoot(this).dataset.exportErrorForbidden,
                        workspaceChanged: outputRoot(this).dataset.exportErrorWorkspaceChanged,
                        invalid: outputRoot(this).dataset.exportErrorInvalid,
                        throttled: outputRoot(this).dataset.exportErrorThrottled,
                        failed: outputRoot(this).dataset.exportErrorFailed,
                    }));
                }
                const html = await response.text();
                if (! popup.closed) {
                    popup.document.open();
                    popup.document.write(html);
                    popup.document.close();
                    popup.focus();
                }
                return true;
            } catch (error) {
                if (! popup.closed) {
                    popup.close();
                }
                this.outputError = error instanceof Error && error.message ? error.message : outputRoot(this).dataset.exportErrorFailed;
                return false;
            } finally {
                this.outputPending = false;
            }
        },

        async exportList(format) {
            if (this.outputPending || this.currentResultsAreEmpty()) {
                return false;
            }

            this.outputPending = true;
            this.outputError = '';

            try {
                const parameters = this.captureParameters();
                parameters.format = format;
                const url = buildListOutputUrl({
                    endpoint: outputRoot(this).dataset.exportUrl,
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
                        access: outputRoot(this).dataset.exportErrorAccess,
                        forbidden: outputRoot(this).dataset.exportErrorForbidden,
                        workspaceChanged: outputRoot(this).dataset.exportErrorWorkspaceChanged,
                        invalid: outputRoot(this).dataset.exportErrorInvalid,
                        throttled: outputRoot(this).dataset.exportErrorThrottled,
                        failed: outputRoot(this).dataset.exportErrorFailed,
                    }));
                }

                const fallback = outputRoot(this).dataset.exportFilenameFallback?.replace('{format}', format) ?? `ingredients.${format}`;
                const filename = filenameFromContentDisposition(response.headers.get('Content-Disposition'), fallback);
                downloadBlob(await response.blob(), filename, document, URLApi, schedule);

                return true;
            } catch (error) {
                this.outputError = error instanceof Error && error.message
                    ? error.message
                    : outputRoot(this).dataset.exportErrorFailed;

                return false;
            } finally {
                this.outputPending = false;
            }
        },
    };
}
