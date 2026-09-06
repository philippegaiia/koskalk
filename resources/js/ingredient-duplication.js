const DEFAULT_MESSAGES = {
    searchFailed: 'Search could not be completed. Refresh the page and try again.',
    authExpired: 'Your session expired. Refresh the page, sign in again, and reopen duplication.',
    duplicateFailed: 'The private copy could not be created. Refresh the page and try again.',
    invalidResponse: 'The server returned an unexpected response. Refresh the page and try again.',
    reloadGuidance: 'Refresh the page and try again.',
    unavailable: 'This ingredient is not available for duplication.',
    sources: {
        platform: 'Soapkraft',
        user: 'Your ingredient',
        workspace: 'Workspace ingredient',
    },
};

const DEFAULT_MAX_IDENTIFIERS = 4;
const DEFAULT_MAX_ALIASES = 4;
const DEFAULT_MAX_FATTY_ACIDS = 20;

function firstMessage(value) {
    if (typeof value === 'string' && value.trim() !== '') {
        return value.trim();
    }

    if (Array.isArray(value)) {
        for (const item of value) {
            const message = firstMessage(item);

            if (message !== null) {
                return message;
            }
        }
    }

    if (value !== null && typeof value === 'object') {
        for (const item of Object.values(value)) {
            const message = firstMessage(item);

            if (message !== null) {
                return message;
            }
        }
    }

    return null;
}

function responseMessage(payload) {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }

    return firstMessage(payload.errors) ?? firstMessage(payload.message);
}

function withReloadGuidance(message, guidance) {
    if (!guidance || message.toLocaleLowerCase().includes(guidance.toLocaleLowerCase())) {
        return message;
    }

    return `${message} ${guidance}`;
}

function errorMessage(error, operation, messages) {
    const status = Number(error?.status ?? 0);

    if ([401, 419].includes(status)) {
        return messages.authExpired;
    }

    if (error?.unexpected === true) {
        return messages.invalidResponse;
    }

    const payloadError = responseMessage(error?.payload);
    const fallback = operation === 'search' ? messages.searchFailed : messages.duplicateFailed;
    const responseError = error?.status > 0 && typeof error?.message === 'string' && error.message.trim() !== ''
        ? error.message
        : null;
    const message = payloadError ?? responseError ?? fallback;

    if ([403, 409, 422].includes(status)) {
        return withReloadGuidance(message, messages.reloadGuidance);
    }

    return message;
}

async function decodeResponse(response) {
    if (typeof response?.json === 'function') {
        try {
            return await response.json();
        } catch {
            // A proxy or expired session can return HTML where JSON was expected.
        }
    }

    if (typeof response?.text === 'function') {
        try {
            const text = await response.text();

            return text.trim() === '' ? null : JSON.parse(text);
        } catch {
            return null;
        }
    }

    return null;
}

function boundedDisplayValue(value, maxLength = 32) {
    if (typeof value !== 'string' && typeof value !== 'number') {
        return null;
    }

    const displayValue = String(value).trim();

    return displayValue === '' ? null : displayValue.slice(0, maxLength);
}

function normalizedRange(range) {
    if (range === null || typeof range !== 'object') {
        return null;
    }

    const normalized = {
        minimum: boundedDisplayValue(range.minimum),
        maximum: boundedDisplayValue(range.maximum),
        original: boundedDisplayValue(range.original),
    };

    return normalized.minimum === null || normalized.maximum === null
        ? null
        : normalized;
}

function normalizedChemistry(chemistry, maxFattyAcids) {
    if (chemistry === null || typeof chemistry !== 'object') {
        return null;
    }

    const kohSap = normalizedRange(chemistry.koh_sap);
    const naohSap = normalizedRange(chemistry.naoh_sap);
    const fattyAcidTotal = normalizedRange(chemistry.fatty_acid_total);

    if (kohSap === null || naohSap === null || fattyAcidTotal === null) {
        return null;
    }

    const fattyAcids = Array.isArray(chemistry.fatty_acids)
        ? chemistry.fatty_acids
            .filter((fattyAcid) => fattyAcid !== null && typeof fattyAcid === 'object')
            .slice(0, maxFattyAcids)
            .map((fattyAcid) => {
                const id = Number(fattyAcid.id);
                const name = boundedDisplayValue(fattyAcid.name, 120);
                const minimum = boundedDisplayValue(fattyAcid.minimum);
                const maximum = boundedDisplayValue(fattyAcid.maximum);
                const original = boundedDisplayValue(fattyAcid.original);
                const display = boundedDisplayValue(fattyAcid.display, 180);

                if (!Number.isSafeInteger(id) || id < 1 || name === null || minimum === null || maximum === null) {
                    return null;
                }

                return { id, name, minimum, maximum, original, display };
            })
            .filter(Boolean)
        : [];

    return {
        koh_sap: kohSap,
        naoh_sap: naohSap,
        fatty_acid_total: fattyAcidTotal,
        fatty_acids: fattyAcids,
    };
}

function normalizedCandidate(candidate, maxIdentifiers, maxAliases, maxFattyAcids) {
    const duplication = candidate?.duplication !== null && typeof candidate?.duplication === 'object'
        ? candidate.duplication
        : {};

    return {
        ...candidate,
        identifiers: Array.isArray(candidate?.identifiers)
            ? candidate.identifiers
                .filter((identifier) => identifier !== null && typeof identifier === 'object')
                .slice(0, maxIdentifiers)
            : [],
        aliases: Array.isArray(candidate?.aliases)
            ? candidate.aliases.slice(0, maxAliases)
            : [],
        duplication: {
            available: duplication.available === true,
            reason: duplication.reason ?? null,
            inherits_soap_chemistry: duplication.inherits_soap_chemistry === true,
            chemistry: normalizedChemistry(duplication.chemistry, maxFattyAcids),
        },
    };
}

function csrfToken() {
    if (typeof document === 'undefined' || typeof document.querySelector !== 'function') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function defaultNavigate(url) {
    if (typeof window === 'undefined') {
        return;
    }

    if (typeof window.location?.assign === 'function') {
        window.location.assign(url);
    }
}

/**
 * Build the Alpine state for the platform ingredient duplication dialog.
 *
 * Network and navigation functions are injectable so request races and
 * failure states can be tested without a browser or another dependency.
 */
export function createIngredientDuplicationModal(options = {}) {
    const messages = {
        ...DEFAULT_MESSAGES,
        ...(options.messages ?? {}),
        sources: {
            ...DEFAULT_MESSAGES.sources,
            ...(options.messages?.sources ?? {}),
        },
    };
    const fetchImpl = options.fetch ?? globalThis.fetch?.bind(globalThis);
    const maxIdentifiers = options.maxIdentifiers ?? DEFAULT_MAX_IDENTIFIERS;
    const maxAliases = options.maxAliases ?? DEFAULT_MAX_ALIASES;
    const maxFattyAcids = options.maxFattyAcids ?? DEFAULT_MAX_FATTY_ACIDS;

    return {
        open: false,
        query: '',
        results: [],
        loading: false,
        searchError: null,
        selected: null,
        duplicateError: null,
        confirming: false,
        redirecting: false,
        opener: null,
        searchGeneration: 0,
        searchController: null,
        searchUrl: options.searchUrl ?? '',
        duplicateUrl: options.duplicateUrl ?? '',
        destinationWorkspaceId: options.destinationWorkspaceId ?? null,
        destinationWorkspaceSignature: options.destinationWorkspaceSignature ?? '',
        destinationLabel: options.destinationLabel ?? '',
        lipidCategoryLabel: options.lipidCategoryLabel ?? 'Lipids',
        messages,

        init() {
            this.searchError = null;
            this.duplicateError = null;
        },

        sourceLabel(candidate) {
            const source = String(candidate?.source ?? '').trim();

            return this.messages.sources?.[source] ?? this.messages.sources?.platform ?? '';
        },

        destroy() {
            this.abortSearch();
        },

        openModal() {
            if (this.open || this.confirming || this.redirecting) {
                return false;
            }

            this.opener = this.$refs?.opener ?? null;
            this.open = true;
            this.query = '';
            this.results = [];
            this.selected = null;
            this.searchError = null;
            this.duplicateError = null;
            this.$nextTick?.(() => this.$refs?.searchInput?.focus());

            return true;
        },

        closeModal() {
            if (this.confirming || this.redirecting) {
                return false;
            }

            const opener = this.opener;

            this.abortSearch();
            this.searchGeneration++;
            this.open = false;
            this.query = '';
            this.results = [];
            this.selected = null;
            this.searchError = null;
            this.duplicateError = null;
            this.opener = null;
            this.$nextTick?.(() => opener?.focus?.());

            return true;
        },

        chooseAnother() {
            if (this.confirming || this.redirecting) {
                return false;
            }

            this.selected = null;
            this.duplicateError = null;
            this.$nextTick?.(() => this.$refs?.searchInput?.focus());

            return true;
        },

        selectCandidate(candidate) {
            if (
                this.confirming
                || this.redirecting
                || candidate === null
                || candidate === undefined
                || candidate.id === null
                || candidate.id === undefined
            ) {
                return false;
            }

            this.selected = normalizedCandidate(candidate, maxIdentifiers, maxAliases, maxFattyAcids);
            this.open = true;
            this.duplicateError = null;
            this.$nextTick?.(() => this.$refs?.previewHeading?.focus());

            return true;
        },

        abortSearch() {
            this.searchController?.abort?.();
            this.searchController = null;
            this.loading = false;
        },

        async requestJson(url, init, operation) {
            if (typeof fetchImpl !== 'function') {
                const unavailable = new Error('fetch is unavailable');
                unavailable.status = 0;
                throw unavailable;
            }

            let response;

            try {
                response = await fetchImpl(url, init);
            } catch (cause) {
                if (cause?.name === 'AbortError') {
                    throw cause;
                }

                const networkError = new Error(operation === 'search' ? messages.searchFailed : messages.duplicateFailed);
                networkError.status = 0;
                networkError.cause = cause;
                throw networkError;
            }

            const payload = await decodeResponse(response);
            const status = Number(response?.status ?? 0);
            const responseIsOk = response?.ok === true || (response?.ok === undefined && status >= 200 && status < 300);

            if (!responseIsOk) {
                const requestError = new Error(responseMessage(payload) ?? '');
                requestError.status = status;
                requestError.payload = payload;
                throw requestError;
            }

            return payload;
        },

        async search() {
            if (this.confirming || this.redirecting) {
                return [];
            }

            const query = String(this.query ?? '').trim();
            const generation = ++this.searchGeneration;

            this.abortSearch();

            if (query.length < 2) {
                this.results = [];
                this.loading = false;
                this.searchError = null;

                return [];
            }

            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            this.searchController = controller;
            this.loading = true;
            this.searchError = null;

            const separator = this.searchUrl.includes('?') ? '&' : '?';
            const url = `${this.searchUrl}${separator}q=${encodeURIComponent(query)}`;

            try {
                const payload = await this.requestJson(url, {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    ...(controller ? { signal: controller.signal } : {}),
                }, 'search');

                if (generation !== this.searchGeneration) {
                    return [];
                }

                if (!Array.isArray(payload)) {
                    const invalidResponse = new Error(messages.invalidResponse);
                    invalidResponse.unexpected = true;
                    throw invalidResponse;
                }

                this.results = payload
                    .filter((candidate) => candidate !== null && typeof candidate === 'object')
                    .map((candidate) => normalizedCandidate(candidate, maxIdentifiers, maxAliases, maxFattyAcids));

                return this.results;
            } catch (error) {
                if (generation !== this.searchGeneration || error?.name === 'AbortError') {
                    return [];
                }

                this.results = [];
                this.searchError = errorMessage(error, 'search', messages);

                return [];
            } finally {
                if (generation === this.searchGeneration) {
                    this.loading = false;

                    if (this.searchController === controller) {
                        this.searchController = null;
                    }
                }
            }
        },

        chemistryState(candidate = this.selected) {
            if (!candidate || candidate.duplication?.available !== true || !this.isLipid(candidate)) {
                return null;
            }

            return candidate.duplication.inherits_soap_chemistry ? 'inherited' : 'untrusted';
        },

        isLipid(candidate = this.selected) {
            const category = String(candidate?.category_key ?? candidate?.category ?? '').trim().toLocaleLowerCase();
            const lipidCategory = String(this.lipidCategoryLabel ?? 'lipids').trim().toLocaleLowerCase();

            return category === 'lipids' || category === lipidCategory;
        },

        trapFocus(event) {
            if (event.key !== 'Tab') {
                return;
            }

            const dialog = this.$refs?.dialog;

            if (!dialog || typeof dialog.querySelectorAll !== 'function') {
                return;
            }

            const focusable = [...dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )];

            if (focusable.length === 0) {
                event.preventDefault();
                dialog.focus?.();

                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const active = typeof document === 'undefined' ? null : document.activeElement;

            if (event.shiftKey && (active === first || !dialog.contains?.(active))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || !dialog.contains?.(active))) {
                event.preventDefault();
                first.focus();
            }
        },

        async confirmDuplicate() {
            if (!this.selected || this.selected.duplication?.available !== true || this.confirming || this.redirecting) {
                return null;
            }

            this.confirming = true;
            this.duplicateError = null;

            try {
                const payload = await this.requestJson(this.duplicateUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        ingredient_id: this.selected.id,
                        destination_workspace_id: this.destinationWorkspaceId,
                        destination_workspace_signature: this.destinationWorkspaceSignature,
                    }),
                }, 'duplicate');

                if (
                    payload === null
                    || typeof payload !== 'object'
                    || payload.ok !== true
                    || typeof payload.redirect !== 'string'
                    || payload.redirect.trim() === ''
                ) {
                    const invalidResponse = new Error(messages.invalidResponse);
                    invalidResponse.unexpected = true;
                    throw invalidResponse;
                }

                (options.navigate ?? defaultNavigate)(payload.redirect);
                this.redirecting = true;

                return payload;
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.duplicateError = errorMessage(error, 'duplicate', messages);
                }

                return null;
            } finally {
                this.confirming = false;
            }
        },
    };
}
