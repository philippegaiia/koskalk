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

async function responseError(response) {
    let body = null;

    try {
        body = await response.json();
    } catch (error) {
        void error;
    }

    const firstValidationMessage = Object.values(body?.errors ?? {})
        .flat()
        .find((message) => typeof message === 'string' && message !== '');
    const message = firstValidationMessage ?? body?.message;

    if (message) {
        return message;
    }

    return ({
        403: 'You no longer have access to this formula or one of its referenced records.',
        404: 'The current formula or one of its referenced records could not be found.',
        409: 'The active workspace changed. Reload the workbench and try again.',
        422: 'This formula cannot be printed or exported yet. Review the current formula details.',
        429: 'Too many output requests. Wait a moment and try again.',
    })[response.status] ?? 'The formula output could not be created. Try again.';
}

function clearFallback(state) {
    if (state.outputFallbackUrl && state.outputFallbackUrl.startsWith('blob:')) {
        URL.revokeObjectURL(state.outputFallbackUrl);
    }

    state.outputFallbackUrl = null;
}

function fallbackPage(state, html) {
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
    state.outputFallbackUrl = url;
    state.outputFallbackLabel = 'Open the prepared formula sheet';
}

export function createRecipeWorkbenchOutputSection(config) {
    return {
        outputConfig: config ?? null,
        outputError: '',
        outputFallbackUrl: null,
        outputFallbackLabel: '',

        async captureOutput(options = {}) {
            if (!this.outputConfig) {
                throw new Error('Current formula output is available only from an authenticated workspace workbench.');
            }

            return captureRecipeWorkbenchOutput(this, this.outputConfig, options);
        },

        async printCurrentFormula(options = {}) {
            const popup = typeof window !== 'undefined' ? window.open('', '_blank') : null;
            this.outputError = '';
            clearFallback(this);

            if (popup) {
                popup.document.write('<!doctype html><title>Preparing formula sheet…</title><p>Preparing formula sheet…</p>');
            }

            try {
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
                    throw new Error(await responseError(response));
                }

                const html = await response.text();

                if (popup && ! popup.closed) {
                    popup.document.open();
                    popup.document.write(html);
                    popup.document.close();
                    popup.focus();
                } else {
                    fallbackPage(this, html);
                    this.outputError = 'Your browser blocked the print tab. Use the prepared formula sheet link to open it.';
                }

                return true;
            } catch (error) {
                if (popup && ! popup.closed) {
                    popup.close();
                }

                this.outputError = error instanceof Error ? error.message : 'The formula sheet could not be prepared.';

                return false;
            }
        },

        async exportCurrentFormula(format = 'xlsx') {
            this.outputError = '';
            clearFallback(this);

            try {
                const payload = await this.captureOutput({ format });
                const response = await fetch(this.outputConfig.exportUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: requestHeaders(),
                    body: JSON.stringify(payload),
                });

                if (! response.ok) {
                    throw new Error(await responseError(response));
                }

                const filename = downloadFilename(response.headers.get('Content-Disposition'));
                const objectUrl = URL.createObjectURL(await response.blob());
                const anchor = document.createElement('a');
                anchor.href = objectUrl;
                anchor.download = filename;
                anchor.hidden = true;
                document.body.append(anchor);
                anchor.click();
                anchor.remove();
                setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);

                return true;
            } catch (error) {
                this.outputError = error instanceof Error ? error.message : 'The formula export could not be created.';

                return false;
            }
        },
    };
}

export function downloadFilename(contentDisposition) {
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
        ?? 'current-formula.xlsx';
}
