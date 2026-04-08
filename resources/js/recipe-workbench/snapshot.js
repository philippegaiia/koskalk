import { number } from './utils';

export function draftStateFromDraft(draft, currentState) {
    if (!draft) {
        return null;
    }

    const nextState = {
        recipeId: draft.recipe?.id ?? currentState.recipeId,
        draftVersionId: draft.recipe?.draft_version_id ?? currentState.draftVersionId,
        currentVersionNumber: draft.recipe?.version_number ?? currentState.currentVersionNumber,
        currentVersionIsDraft: draft.recipe?.is_draft ?? currentState.currentVersionIsDraft,
        formulaName: draft.formulaName ?? currentState.formulaName,
        oilUnit: draft.oilUnit ?? currentState.oilUnit,
        oilWeight: number(draft.oilWeight ?? currentState.oilWeight),
        manufacturingMode: ['saponify_in_formula', 'blend_only'].includes(draft.manufacturingMode) ? draft.manufacturingMode : currentState.manufacturingMode,
        exposureMode: ['rinse_off', 'leave_on'].includes(draft.exposureMode) ? draft.exposureMode : currentState.exposureMode,
        regulatoryRegime: ['eu'].includes(draft.regulatoryRegime) ? draft.regulatoryRegime : currentState.regulatoryRegime,
        editMode: draft.editMode === 'weight' ? 'weight' : 'percentage',
        lyeType: ['naoh', 'koh', 'dual'].includes(draft.lyeType) ? draft.lyeType : currentState.lyeType,
        kohPurity: number(draft.kohPurity ?? currentState.kohPurity),
        dualKohPercentage: number(draft.dualKohPercentage ?? currentState.dualKohPercentage),
        waterMode: ['percent_of_oils', 'lye_ratio', 'lye_concentration'].includes(draft.waterMode) ? draft.waterMode : currentState.waterMode,
        waterValue: number(draft.waterValue ?? currentState.waterValue),
        superfat: number(draft.superfat ?? currentState.superfat),
        phaseItems: {
            saponified_oils: draft.phaseItems?.saponified_oils ?? [],
            additives: draft.phaseItems?.additives ?? [],
            fragrance: draft.phaseItems?.fragrance ?? [],
        },
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
        inciCopyMessage: '',
    };

    return nextState;
}
