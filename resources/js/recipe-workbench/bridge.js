import { serializeCosting, serializeDraft } from './payload';

/**
 * Preview calls are best-effort for incomplete drafts, but validation failures
 * returned by the server are surfaced so reachable controls do not silently
 * remove calculated outputs.
 */
export async function refreshCalculationPreview(workbench) {
    try {
        const response = await workbench.$wire.previewCalculation(serializeDraft(workbench));

        if (response?.ok) {
            workbench.backendCalculation = response.calculation ?? null;
            workbench.backendLabeling = response.labeling ?? null;
            workbench.backendRestrictions = response.restrictions ?? null;
            workbench.syncIngredientListVariantSelection();
            workbench.inciCopyMessage = '';
            workbench.calculationPreviewMessage = '';
        } else {
            workbench.backendCalculation = null;
            workbench.backendLabeling = null;
            workbench.backendRestrictions = null;
            workbench.calculationPreviewMessage = response?.message ?? 'The live calculation preview is not available for these inputs.';
        }
    } catch (error) {
        workbench.backendCalculation = null;
        workbench.backendLabeling = null;
        workbench.backendRestrictions = null;
        workbench.calculationPreviewMessage = 'The live calculation preview is not available for these inputs.';
    } finally {
        workbench.isPreviewingCalculation = false;
        workbench.calculationPreviewTimer = null;
        workbench.releasePendingPreview();
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
            workbench.costingSaveMessage = response?.message ?? workbench.t('costing.messages.save_failed');

            return;
        }

        workbench.applyCostingPayload(response.costing ?? null);
        workbench.costingSaveStatus = 'success';
        workbench.costingSaveMessage = response.message ?? workbench.t('costing.messages.saved');
    } catch (error) {
        if (seq !== workbench.costingSaveSeq) {
            return;
        }

        workbench.costingSaveStatus = 'error';
        workbench.costingSaveMessage = workbench.t('costing.messages.save_failed');
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
            workbench.packagingCatalogMessage = response?.message ?? workbench.t('packaging.messages.save_failed');

            return false;
        }

        workbench.packagingCatalog = response.packaging_catalog ?? [];
        workbench.packagingCatalogStatus = 'success';
        workbench.packagingCatalogMessage = response.message ?? workbench.t('packaging.messages.saved');

        return response.packaging_item ?? null;
    } catch (error) {
        workbench.packagingCatalogStatus = 'error';
        workbench.packagingCatalogMessage = workbench.t('packaging.messages.save_failed');

        return false;
    }
}
