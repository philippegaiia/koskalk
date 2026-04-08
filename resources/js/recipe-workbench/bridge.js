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

        if (response.redirect) {
            window.location.href = response.redirect;
        }
    } catch (error) {
        workbench.saveStatus = 'error';
        workbench.saveMessage = 'The formula could not be saved.';
    } finally {
        workbench.isSaving = false;
    }
}

export async function persistCosting(workbench) {
    workbench.isSavingCosting = true;
    workbench.costingSaveStatus = null;

    try {
        const response = await workbench.$wire.saveCosting(serializeCosting(workbench));

        if (!response?.ok) {
            workbench.costingSaveStatus = 'error';
            workbench.costingSaveMessage = response?.message ?? 'The costing data could not be saved.';

            return;
        }

        workbench.applyCostingPayload(response.costing ?? null);
        workbench.costingSaveStatus = 'success';
        workbench.costingSaveMessage = response.message ?? 'Costing saved.';
    } catch (error) {
        workbench.costingSaveStatus = 'error';
        workbench.costingSaveMessage = 'The costing data could not be saved.';
    } finally {
        workbench.isSavingCosting = false;
    }
}

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

        return true;
    } catch (error) {
        workbench.packagingCatalogStatus = 'error';
        workbench.packagingCatalogMessage = 'The packaging item could not be saved.';

        return false;
    }
}

export async function destroyPackagingCatalogItem(workbench, packagingItemId) {
    workbench.packagingCatalogStatus = 'saving';
    workbench.packagingCatalogMessage = '';

    try {
        const response = await workbench.$wire.deletePackagingCatalogItem(packagingItemId);

        if (!response?.ok) {
            workbench.packagingCatalogStatus = 'error';
            workbench.packagingCatalogMessage = response?.message ?? 'The packaging item could not be deleted.';

            return false;
        }

        workbench.packagingCatalog = response.packaging_catalog ?? [];
        workbench.packagingCatalogStatus = 'success';
        workbench.packagingCatalogMessage = response.message ?? 'Packaging item deleted.';

        return true;
    } catch (error) {
        workbench.packagingCatalogStatus = 'error';
        workbench.packagingCatalogMessage = 'The packaging item could not be deleted.';

        return false;
    }
}
