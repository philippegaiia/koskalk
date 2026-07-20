export function createVersionSection() {
    return {
        get hasCurrentFormula() {
            return this.recipeId !== null;
        },

        get hasSavedRecipe() {
            return this.hasCurrentFormula;
        },

        get hasCurrentPublishedFormula() {
            return Boolean(this.savedRecipeUrl);
        },

        get formulaWorkbenchLabel() {
            if (this.isFormulaLocked) {
                return this.t('header.locked');
            }

            return this.hasCurrentFormula ? this.t('header.current_product') : this.t('header.new_product');
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

        get selectedRegulatoryRegimeRecord() {
            return this.regulatoryRegimes.find((regime) => regime.code === this.regulatoryRegime) ?? null;
        },

        get regulatoryRegimeLabel() {
            return this.selectedRegulatoryRegimeRecord?.name ?? this.regulatoryRegime.toUpperCase();
        },

        get regulatoryRegimeCoverageLabel() {
            const allergenCount = Number(this.selectedRegulatoryRegimeRecord?.allergen_rule_count ?? 0);
            const substanceCount = Number(this.selectedRegulatoryRegimeRecord?.substance_rule_count ?? 0);

            if (allergenCount <= 0 && substanceCount <= 0) {
                return 'No screening rules for this regime.';
            }

            const labels = [];

            if (allergenCount > 0) {
                labels.push(`${allergenCount} ${allergenCount === 1 ? 'allergen' : 'allergens'}`);
            }

            if (substanceCount > 0) {
                labels.push(`${substanceCount} ${substanceCount === 1 ? 'substance' : 'substances'}`);
            }

            return labels.join(' · ');
        },

        get hasPostReactionRows() {
            return this.additiveRows.length > 0 || this.fragranceRows.length > 0;
        },
    };
}
