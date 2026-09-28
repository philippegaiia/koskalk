import { serializeDraft } from './payload';

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function nullableNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function serializeOutputCosting(state) {
    return {
        oil_weight_for_costing: nullableNumber(state.costingOilWeight),
        oil_unit_for_costing: state.costingOilUnit ?? null,
        units_produced: nullableNumber(state.costingUnitsProduced),
        currency: state.costingCurrency ?? null,
        items: (state.costingFormulaRows ?? []).map((row) => ({
            id: row.id ?? row.rowId ?? null,
            ingredient_id: row.ingredient_id ?? null,
            phase_key: row.phaseKey ?? row.phase_key ?? null,
            position: row.position ?? null,
            price_per_kg: nullableNumber(state.canonicalPricePerKg?.(row)),
        })),
        packaging_items: (state.packagingCostRows ?? []).map((row) => ({
            packaging_item_id: row.packaging_item_id ?? null,
            name: row.name ?? '',
            unit_cost: nullableNumber(row.unit_cost),
            components_per_unit: nullableNumber(row.components_per_unit ?? row.quantity),
        })),
    };
}

export async function captureRecipeWorkbenchOutput(state, config, options = {}) {
    if (typeof state.$nextTick === 'function') {
        await state.$nextTick();
    }

    const draft = serializeDraft(state);
    const payload = {
        workspace: config?.workspace ?? null,
        recipe: config?.recipe ?? null,
        product_family: config?.productFamily ?? state.productFamilySlug ?? null,
        kind: 'formula',
        format: options.format ?? 'xlsx',
        draft,
        manufacturing_instructions: state.$wire?.data?.manufacturing_instructions ?? null,
        costing: state.hasLoadedCosting ? serializeOutputCosting(state) : null,
    };

    if (options.printContext) {
        payload.print_context = options.printContext;
    }

    if (Array.isArray(options.sections)) {
        payload.sections = options.sections.slice();
    }

    return clone(payload);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function requestHeaders() {
    return {
        Accept: 'application/json, text/html;q=0.9, */*;q=0.8',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    };
}

function outputMessage(messages, key) {
    return messages?.[key] ?? '';
}

async function responseError(response, messages) {
    let body = null;

    try {
        body = await response.json();
    } catch (error) {
        void error;
    }

    const firstValidationMessage = Object.values(body?.errors ?? {})
        .flat()
        .find((message) => typeof message === 'string' && message !== '');

    if (firstValidationMessage) {
        return firstValidationMessage;
    }

    const statusMessage = ({
        401: 'accessRequired',
        403: 'forbidden',
        404: 'notFound',
        409: 'workspaceChanged',
        422: 'invalidFormula',
        429: 'throttled',
    })[response.status];

    return outputMessage(messages, statusMessage ?? 'failed') || body?.message || outputMessage(messages, 'failed');
}

class OutputRequestError extends Error {}

function clearFallback(state) {
    if (state.outputFallbackUrl && state.outputFallbackUrl.startsWith('blob:')) {
        URL.revokeObjectURL(state.outputFallbackUrl);
    }

    state.outputFallbackUrl = null;
    state.outputFallbackLabel = '';
}

function fallbackPage(state, html, messages) {
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
    state.outputFallbackUrl = url;
    state.outputFallbackLabel = outputMessage(messages, 'fallbackLink');
}

function showPreparingPage(popup, messages) {
    popup.document.title = outputMessage(messages, 'preparingTitle');

    const message = popup.document.createElement('p');
    message.textContent = outputMessage(messages, 'preparingSheet');
    popup.document.body.replaceChildren(message);
}

function outputErrorMessage(error, messages, fallbackKey) {
    return error instanceof OutputRequestError
        ? error.message
        : outputMessage(messages, fallbackKey);
}

function appendDownload(blob, filename) {
    const objectUrl = URL.createObjectURL(blob);
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
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    }
}

export function createRecipeWorkbenchOutputSection(config) {
    return {
        outputConfig: config ?? null,
        outputPending: false,
        outputError: '',
        outputFallbackUrl: null,
        outputFallbackLabel: '',
        outputDestroyed: false,

        destroyOutputSection() {
            this.outputDestroyed = true;
            clearFallback(this);
        },

        async captureOutput(options = {}) {
            if (!this.outputConfig) {
                throw new OutputRequestError(outputMessage(this.outputConfig?.messages, 'failed'));
            }

            return captureRecipeWorkbenchOutput(this, this.outputConfig, options);
        },

        async printCurrentFormula(options = {}) {
            if (this.outputPending) {
                return false;
            }

            const popup = typeof window !== 'undefined' ? window.open('', '_blank') : null;
            this.outputPending = true;
            this.outputError = '';

            try {
                clearFallback(this);

                if (popup) {
                    showPreparingPage(popup, this.outputConfig?.messages);
                }

                const payload = await this.captureOutput({
                    format: 'xlsx',
                    printContext: options.printContext ?? 'formula',
                    sections: options.sections,
                });
                const response = await fetch(this.outputConfig.printUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: requestHeaders(),
                    body: JSON.stringify(payload),
                });

                if (! response.ok) {
                    throw new OutputRequestError(await responseError(response, this.outputConfig.messages));
                }

                const html = await response.text();

                if (popup && ! popup.closed) {
                    popup.document.open();
                    popup.document.write(html);
                    popup.document.close();
                    popup.focus();
                } else if (! this.outputDestroyed) {
                    fallbackPage(this, html, this.outputConfig.messages);
                    this.outputError = outputMessage(this.outputConfig.messages, 'browserBlocked');
                }

                return true;
            } catch (error) {
                if (popup && ! popup.closed) {
                    popup.close();
                }

                this.outputError = outputErrorMessage(error, this.outputConfig?.messages, 'printFailed');

                return false;
            } finally {
                this.outputPending = false;
            }
        },

        async exportCurrentFormula(format = 'xlsx') {
            if (this.outputPending) {
                return false;
            }

            this.outputPending = true;
            this.outputError = '';

            try {
                clearFallback(this);

                const payload = await this.captureOutput({ format });
                const response = await fetch(this.outputConfig.exportUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: requestHeaders(),
                    body: JSON.stringify(payload),
                });

                if (! response.ok) {
                    throw new OutputRequestError(await responseError(response, this.outputConfig.messages));
                }

                const fallbackFilename = outputMessage(
                    this.outputConfig.messages,
                    format === 'csv' ? 'defaultFilenameCsv' : 'defaultFilenameXlsx',
                );
                const filename = downloadFilename(response.headers.get('Content-Disposition'), fallbackFilename);
                appendDownload(await response.blob(), filename);

                return true;
            } catch (error) {
                this.outputError = outputErrorMessage(error, this.outputConfig?.messages, 'exportFailed');

                return false;
            } finally {
                this.outputPending = false;
            }
        },
    };
}

export function downloadFilename(contentDisposition, fallbackFilename = '') {
    const header = typeof contentDisposition === 'string' ? contentDisposition : '';
    const encoded = header.match(/filename\*\s*=\s*UTF-8''([^;]+)/i)?.[1];

    if (encoded) {
        try {
            return decodeURIComponent(encoded.trim().replace(/^"|"$/g, ''));
        } catch (error) {
            void error;
        }
    }

    return header.match(/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i)?.[1]?.trim()
        ?? header.match(/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i)?.[2]?.trim()
        ?? fallbackFilename;
}
