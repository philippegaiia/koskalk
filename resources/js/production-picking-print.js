export function capturePickingAllocations(root) {
    const allocations = [];
    for (const requirement of root.querySelectorAll('[data-picking-requirement]')) {
        const manual = requirement.dataset.pickingManual === 'true';
        for (const row of requirement.querySelectorAll(manual ? '[data-picking-manual-lot]' : '[data-picking-auto-lot]')) {
            const quantity = manual ? row.value.trim() : row.dataset.pickingQuantity;
            if (manual && quantity === '') continue;
            allocations.push({ requirement_id: Number(requirement.dataset.pickingRequirement), stock_lot_id: Number(manual ? row.dataset.pickingManualLot : row.dataset.pickingAutoLot), quantity });
        }
    }
    return { workspace: root.dataset.pickingWorkspace, runs: JSON.parse(root.dataset.pickingRuns), allocations };
}

export function createProductionPickingPrint(dependencies = {}) {
    const window = dependencies.window ?? globalThis.window;
    const document = dependencies.document ?? globalThis.document;
    const fetch = dependencies.fetch ?? globalThis.fetch;
    let root = null;
    // Inside an event handler Alpine resolves this.$el to the clicked element,
    // so the component root (which carries the data attributes) is kept from init().
    const outputRoot = (component) => root ?? component.$el;
    return {
        pending: false,
        error: '',
        init() {
            root = this.$el;
        },
        async printPicking() {
            if (this.pending) return false;
            this.error = '';
            const popup = window.open('', '_blank');
            if (!popup) { this.error = outputRoot(this).dataset.pickingPopup; return false; }
            this.pending = true;
            try {
                const payload = capturePickingAllocations(outputRoot(this));
                const response = await fetch(outputRoot(this).dataset.pickingUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                    body: JSON.stringify(payload),
                });
                if (!response.ok) {
                    const key = ({ 401: 'pickingAccess', 403: 'pickingAccess', 409: 'pickingChanged', 422: 'pickingInvalid', 429: 'pickingThrottled' })[response.status] ?? 'pickingFailed';
                    throw new Error(outputRoot(this).dataset[key]);
                }
                const html = await response.text();
                if (!popup.closed) { popup.document.open(); popup.document.write(html); popup.document.close(); popup.focus(); }
                return true;
            } catch (error) {
                if (!popup.closed) popup.close();
                this.error = error instanceof Error && error.message ? error.message : outputRoot(this).dataset.pickingFailed;
                return false;
            } finally { this.pending = false; }
        },
    };
}
