import { serializeCosting, serializeDraft } from './payload';

/**
 * Preview calls are intentionally best-effort. We clear derived outputs on failure
 * but avoid surfacing save-style errors while the user is still editing.
 */
export async function refreshCalculationPreview(workbench) {
    try {
        const response = await workbench.$wire.previewCalculation(serializeDraft(workbench));

        if (response?.ok) {
            workbench.backendCalculation = response.calculation ?? null;
            workbench.backendLabeling = response.labeling ?? null;
            workbench.syncIngredientListVariantSelection();
            workbench.inciCopyMessage = '';
        }
    } catch (error) {
        workbench.backendCalculation = null;
        workbench.backendLabeling = null;
    } finally {
        workbench.isPreviewingCalculation = false;
        workbench.calculationPreviewTimer = null;
    }
}

/**
 * Labeling preview stays independent from the soap calculation preview so
 * aromatic/additive changes can refresh INCI output without toggling the
 * fatty-acid or lye live-preview state.
 */
export async function refreshLabelingPreview(workbench) {
    try {
        const response = await workbench.$wire.previewLabeling(serializeDraft(workbench));

        if (response?.ok) {
            workbench.backendLabeling = response.labeling ?? null;
            workbench.syncIngredientListVariantSelection();
            workbench.inciCopyMessage = '';
        }
    } catch (error) {
        workbench.backendLabeling = null;
    } finally {
        workbench.labelingPreviewTimer = null;
    }
}

/**
 * Save flows all share the same request contract: send the serialized draft,
 * apply an optional returned snapshot, and follow an optional redirect.
 */
export async function persistWorkbench(workbench, method) {
    workbench.isSaving = true;
    workbench.saveStatus = null;
    workbench.saveMessage = '';

    try {
        const response = await workbench.$wire[method](serializeDraft(workbench));

        if (!response?.ok) {
            workbench.saveStatus = 'error';
            workbench.saveMessage = response?.message ?? 'The formula could not be saved.';

            return;
        }

        workbench.saveStatus = 'success';
        workbench.saveMessage = response.message ?? 'Formula saved.';

        if (response.snapshot) {
            workbench.applySnapshot(response.snapshot);
        }

        workbench.refreshDirtyBaseline();

        if (response.redirect) {
            const hash = workbench.activeWorkbenchTab ? `#${workbench.activeWorkbenchTab}` : '';
            const target = response.redirect + hash;

            if (window.Livewire?.navigate) {
                window.Livewire.navigate(target);

                return;
            }

            window.location.assign(target);
        }
    } catch (error) {
        workbench.saveStatus = 'error';
        workbench.saveMessage = 'The formula could not be saved.';
    } finally {
        workbench.isSaving = false;
    }
}

/**
 * Persist the current costing state (settings, ingredient prices, packaging rows)
 * to the backend. On success, the returned payload is applied to reconcile local
 * state with the server's view. Runs on a 350ms debounce from the costing tab.
 * The seq parameter guards against stale responses from out-of-order saves.
 */
export async function persistCosting(workbench, seq) {
    workbench.isSavingCosting = true;
    workbench.costingSaveStatus = null;

    try {
        const response = await workbench.$wire.saveCosting(serializeCosting(workbench));

        if (seq !== workbench.costingSaveSeq) {
            return;
        }

        if (!response?.ok) {
            workbench.costingSaveStatus = 'error';
            workbench.costingSaveMessage = response?.message ?? 'The costing data could not be saved.';

            return;
        }

        workbench.applyCostingPayload(response.costing ?? null);
        workbench.costingSaveStatus = 'success';
        workbench.costingSaveMessage = response.message ?? 'Costing saved.';
    } catch (error) {
        if (seq !== workbench.costingSaveSeq) {
            return;
        }

        workbench.costingSaveStatus = 'error';
        workbench.costingSaveMessage = 'The costing data could not be saved.';
    } finally {
        if (seq === workbench.costingSaveSeq) {
            workbench.isSavingCosting = false;
        }
    }
}

/**
 * Create or update a reusable packaging catalog item. On success, the updated
 * catalog is applied and the saved item is returned so the caller can optionally
 * add it to the current costing rows.
 */
export async function persistPackagingCatalogItem(workbench, payload) {
    workbench.packagingCatalogStatus = 'saving';
    workbench.packagingCatalogMessage = '';

    try {
        const response = await workbench.$wire.savePackagingCatalogItem(payload);

        if (!response?.ok) {
            workbench.packagingCatalogStatus = 'error';
            workbench.packagingCatalogMessage = response?.message ?? 'The packaging item could not be saved.';

            return false;
        }

        workbench.packagingCatalog = response.packaging_catalog ?? [];
        workbench.packagingCatalogStatus = 'success';
        workbench.packagingCatalogMessage = response.message ?? 'Packaging item saved.';

        return response.packaging_item ?? null;
    } catch (error) {
        workbench.packagingCatalogStatus = 'error';
        workbench.packagingCatalogMessage = 'The packaging item could not be saved.';

        return false;
    }
}
