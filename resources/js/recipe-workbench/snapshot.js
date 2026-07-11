import { number } from './utils';

function phaseItemsFromDraft(draft, phaseOrder) {
    const draftPhaseItems = draft.phaseItems ?? {};
    const nextPhaseItems = {};

    phaseOrder.forEach((phase) => {
        nextPhaseItems[phase.key] = Array.isArray(draftPhaseItems[phase.key])
            ? draftPhaseItems[phase.key]
            : [];
    });

    Object.entries(draftPhaseItems).forEach(([phaseKey, rows]) => {
        if (!Object.hasOwn(nextPhaseItems, phaseKey)) {
            nextPhaseItems[phaseKey] = Array.isArray(rows) ? rows : [];
        }
    });

    return nextPhaseItems;
}

export function draftStateFromDraft(draft, currentState) {
    if (!draft) {
        return null;
    }

    const phaseOrder = Array.isArray(draft.phases) && draft.phases.length > 0
        ? draft.phases
        : currentState.phaseOrder;

    const nextState = {
        recipeId: draft.recipe?.id ?? currentState.recipeId,
        currentVersionId: draft.recipe?.current_version_id ?? currentState.currentVersionId,
        currentVersionNumber: draft.recipe?.version_number ?? currentState.currentVersionNumber,
        currentVersionIsDraft: draft.recipe?.is_current ?? currentState.currentVersionIsDraft,
        productTypeId: draft.productTypeId === null || draft.productTypeId === undefined
            ? ''
            : String(draft.productTypeId),
        formulaName: draft.formulaName ?? currentState.formulaName,
        oilUnit: draft.oilUnit ?? currentState.oilUnit,
        oilWeight: number(draft.oilWeight ?? currentState.oilWeight),
        manufacturingMode: ['saponify_in_formula', 'blend_only'].includes(draft.manufacturingMode) ? draft.manufacturingMode : currentState.manufacturingMode,
        exposureMode: ['rinse_off', 'leave_on'].includes(draft.exposureMode) ? draft.exposureMode : currentState.exposureMode,
        regulatoryRegime: typeof draft.regulatoryRegime === 'string' && draft.regulatoryRegime.trim() !== '' ? draft.regulatoryRegime : currentState.regulatoryRegime,
        editMode: draft.editMode === 'weight' ? 'weight' : 'percentage',
        lyeType: ['naoh', 'koh', 'dual'].includes(draft.lyeType) ? draft.lyeType : currentState.lyeType,
        kohPurity: number(draft.kohPurity ?? currentState.kohPurity),
        dualKohPercentage: number(draft.dualKohPercentage ?? currentState.dualKohPercentage),
        waterMode: ['percent_of_oils', 'lye_ratio', 'lye_concentration'].includes(draft.waterMode) ? draft.waterMode : currentState.waterMode,
        waterValue: number(draft.waterValue ?? currentState.waterValue),
        superfat: number(draft.superfat ?? currentState.superfat),
        finalIngredientList: draft.finalIngredientList ?? currentState.finalIngredientList ?? '',
        finalIngredientListBasisHash: draft.finalIngredientListBasisHash ?? currentState.finalIngredientListBasisHash ?? '',
        finalPlainIngredientList: draft.finalPlainIngredientList ?? currentState.finalPlainIngredientList ?? '',
        finalPlainIngredientListBasisHash: draft.finalPlainIngredientListBasisHash ?? currentState.finalPlainIngredientListBasisHash ?? '',
        phaseOrder,
        phaseItems: phaseItemsFromDraft(draft, phaseOrder),
        packagingPlanRows: Array.isArray(draft.packagingItems)
            ? draft.packagingItems.map((row) => ({
                id: row.id ?? `packaging-plan-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                user_packaging_item_id: row.user_packaging_item_id ?? null,
                name: row.name ?? '',
                components_per_unit: number(row.components_per_unit ?? 1),
                notes: row.notes ?? '',
            }))
            : currentState.packagingPlanRows,
        catalogReview: draft.catalogReview ?? currentState.catalogReview,
    };

    if (Object.hasOwn(draft, 'selectedIfraProductCategoryId')) {
        nextState.selectedIfraProductCategoryId = draft.selectedIfraProductCategoryId === null || draft.selectedIfraProductCategoryId === undefined
            ? ''
            : String(draft.selectedIfraProductCategoryId);
    }

    return nextState;
}

export function snapshotStateFromSnapshot(snapshot, currentState, options = {}) {
    if (!snapshot?.draft) {
        return null;
    }

    void options;
    const nextState = {
        ...draftStateFromDraft(snapshot.draft, currentState),
        backendCalculation: snapshot.calculation ?? null,
        backendLabeling: snapshot.labeling ?? null,
        backendRestrictions: snapshot.restrictions ?? null,
        inciCopyMessage: '',
    };

    return nextState;
}
