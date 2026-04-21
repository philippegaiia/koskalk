export function createVersionSection() {
    return {
        get hasSavedRecipe() {
            return this.recipeId !== null;
        },

        get hasCurrentSavedFormula() {
            return Boolean(this.savedRecipeUrl);
        },

        get formulaWorkbenchLabel() {
            if (!this.hasSavedRecipe || this.currentVersionNumber === null) {
                return 'Editable draft';
            }

            return this.currentVersionIsDraft ? 'Editable draft' : 'Official saved recipe';
        },

        get needsCatalogReview() {
            return Boolean(this.catalogReview?.needs_review);
        },

        get manufacturingModeLabel() {
            return this.manufacturingMode === 'blend_only' ? 'Blend only' : 'Saponify in formula';
        },

        get exposureModeLabel() {
            return this.exposureMode === 'leave_on' ? 'Leave-on' : 'Rinse-off';
        },

        get hasPostReactionRows() {
            return this.additiveRows.length > 0 || this.fragranceRows.length > 0;
        },
    };
}
