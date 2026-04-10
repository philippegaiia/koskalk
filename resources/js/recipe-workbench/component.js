import {
    CATEGORY_OPTIONS,
    fattyAcidLabels as buildFattyAcidLabels,
    filterIngredients as filterIngredientCatalog,
    ingredientCategoryCode as getIngredientCategoryCode,
    ingredientFattyAcidRows as buildIngredientFattyAcidRows,
    ingredientInspectorRows as buildIngredientInspectorRows,
    ingredientMonogram as getIngredientMonogram,
    normalizedIfraProductCategoryId as getNormalizedIfraProductCategoryId,
    resolveTargetPhase as resolveIngredientTargetPhase,
    selectedIfraProductCategory as findSelectedIfraProductCategory,
    targetPhaseForCategory as getTargetPhaseForCategory,
} from './catalog';
import {
    serializeDraft as buildSerializedDraft,
    serializeRow as buildSerializedRow,
} from './payload';
import {
    persistWorkbench,
    refreshCalculationPreview as refreshWorkbenchCalculationPreview,
    refreshLabelingPreview as refreshWorkbenchLabelingPreview,
} from './bridge';
import {
    draftStateFromDraft as buildDraftStateFromDraft,
    snapshotStateFromSnapshot as buildSnapshotStateFromSnapshot,
} from './snapshot';
import { humanizeKey as humanizeText } from './utils';
import { createFormulaSection } from './sections/formula-section';
import { createCostingSection } from './sections/costing-section';
import { createPresentationSection } from './sections/presentation-section';
import { createVersionSection } from './sections/version-section';

/**
 * We compose the Alpine object from descriptor-preserving sections so getters
 * keep working while responsibilities become easier to scan and maintain.
 */
function defineSection(component, section) {
    Object.defineProperties(component, Object.getOwnPropertyDescriptors(section));
}

/**
 * This section owns the public state keys and the boot-time watchers that keep
 * the preview in sync while the user edits the workbench.
 */
function createRecipeWorkbenchState(payload) {
    const initialSnapshot = payload.savedSnapshot ?? null;
    const initialDraft = payload.savedDraft ?? initialSnapshot?.draft ?? null;

    return {
        activeWorkbenchTab: window.location.hash.replace('#', '') || 'formula',
        recipeId: payload.recipe?.id ?? null,
        draftVersionId: payload.recipe?.draft_version_id ?? null,
        currentVersionNumber: payload.recipe?.version_number ?? null,
        currentVersionIsDraft: payload.recipe?.is_draft ?? true,
        formulaName: 'New Soap Formula',
        oilUnit: 'g',
        oilWeight: 1000,
        manufacturingMode: 'saponify_in_formula',
        exposureMode: 'rinse_off',
        regulatoryRegime: 'eu',
        editMode: 'percentage',
        lyeType: 'naoh',
        kohPurity: 90,
        dualKohPercentage: 40,
        waterMode: 'percent_of_oils',
        waterValue: 38,
        superfat: 5,
        search: '',
        activeCategory: 'all',
        ifraProductCategories: (payload.ifraProductCategories ?? []).filter((category) => ['6', '7A', '8', '9', '10A'].includes(`${category.code ?? ''}`.toUpperCase())),
        selectedIfraProductCategoryId: payload.defaultIfraProductCategoryId === null || payload.defaultIfraProductCategoryId === undefined ? '' : String(payload.defaultIfraProductCategoryId),
        ingredients: payload.ingredients ?? [],
        backendCalculation: initialSnapshot?.calculation ?? null,
        backendLabeling: initialSnapshot?.labeling ?? null,
        catalogReview: initialDraft?.catalogReview ?? null,
        selectedIngredientListVariantKey: initialSnapshot?.labeling?.default_variant_key ?? 'saponified_with_superfat',
        savedRecipeUrl: payload.recipe?.saved_formula_url ?? null,
        inciCopyMessage: '',
        calculationPreviewTimer: null,
        labelingPreviewTimer: null,
        isPreviewingCalculation: false,
        draggedRowId: null,
        draggedRowPhaseKey: null,
        dropTargetPhaseKey: null,
        dropTargetRowId: null,
        phaseOrder: payload.phases ?? [],
        saveStatus: null,
        saveMessage: '',
        isSaving: false,
        costingId: payload.costing?.settings?.id ?? null,
        costingOilWeight: payload.costing?.settings?.oilWeightForCosting ?? null,
        costingOilUnit: payload.costing?.settings?.oilUnitForCosting ?? null,
        costingUnitsProduced: payload.costing?.settings?.unitsProduced ?? null,
        costingCurrency: payload.costing?.settings?.currency ?? 'EUR',
        persistedCostingItemPrices: payload.costing?.item_prices ?? [],
        costingPriceByRowId: {},
        packagingCostRows: [],
        packagingCatalog: payload.costing?.packaging_catalog ?? [],
        packagingCatalogForm: {
            id: null,
            name: '',
            unit_cost: '',
            currency: payload.costing?.settings?.currency ?? 'EUR',
            notes: '',
        },
        packagingCatalogModalOpen: false,
        hasLoadedCosting: Boolean(payload.costingLoaded ?? payload.costing),
        isLoadingCosting: false,
        costingSaveTimer: null,
        costingSaveSeq: 0,
        isSavingCosting: false,
        costingSaveStatus: null,
        costingSaveMessage: '',
        packagingCatalogStatus: null,
        packagingCatalogMessage: '',
        lastCalculationPhaseSignature: null,
        phaseItems: {
            saponified_oils: [],
            additives: [],
            fragrance: [],
        },

        init() {
            this.applyDraft(initialDraft);
            this.applySnapshot(initialSnapshot);
            this.initializeCostingState();
            this.resetPackagingCatalogForm();

            if (this.activeWorkbenchTab === 'costing') {
                this.ensureCostingLoaded();
            }

            if (this.phaseItems.saponified_oils.length === 0) {
                const defaultOil = this.filteredIngredients.find((ingredient) => ingredient.can_add_to_saponified_oils);

                if (defaultOil) {
                    this.addIngredient(defaultOil, 'saponified_oils');
                }
            }

            ['oilWeight', 'manufacturingMode', 'exposureMode', 'regulatoryRegime', 'lyeType', 'kohPurity', 'dualKohPercentage', 'waterMode', 'waterValue', 'superfat', 'selectedIfraProductCategoryId'].forEach((key) => {
                this.$watch(key, () => this.scheduleCalculationPreview());
            });

            this.$watch('phaseItems', () => {
                this.reconcileCostingPrices();
                this.schedulePhaseItemPreviews();
            });

            this.lastCalculationPhaseSignature = this.currentCalculationPhaseSignature();

            /**
             * Existing drafts already arrive with a server-rendered snapshot, so
             * we avoid repeating the same preview request on first paint.
             */
            if (!initialSnapshot) {
                this.scheduleCalculationPreview();
            }
        },
    };
}

/**
 * Catalog concerns stay together here: filtering, ingredient badges, and the
 * phase assignment rules used when a user adds or removes ingredients.
 */
function createCatalogSection() {
    return {
        get categoryOptions() {
            return CATEGORY_OPTIONS;
        },

        get filteredIngredients() {
            return filterIngredientCatalog(this.ingredients, this.search, this.activeCategory);
        },

        get selectedIfraProductCategory() {
            return findSelectedIfraProductCategory(this.ifraProductCategories, this.selectedIfraProductCategoryId);
        },

        normalizedIfraProductCategoryId() {
            return getNormalizedIfraProductCategoryId(this.selectedIfraProductCategoryId);
        },

        ingredientMonogram(ingredient) {
            return getIngredientMonogram(ingredient);
        },

        ingredientCategoryCode(ingredient) {
            return getIngredientCategoryCode(ingredient);
        },

        ingredientForRow(row) {
            return this.ingredients.find((ingredient) => Number(ingredient.id) === Number(row?.ingredient_id)) ?? null;
        },

        ingredientHasInspector(ingredient) {
            return this.ingredientInspectorRows(ingredient).length > 0
                || this.ingredientFattyAcidRows(ingredient).length > 0;
        },

        ingredientInspectorRows(ingredient) {
            return buildIngredientInspectorRows(ingredient);
        },

        ingredientFattyAcidRows(ingredient) {
            return buildIngredientFattyAcidRows(ingredient);
        },

        fattyAcidLabels() {
            return buildFattyAcidLabels();
        },

        humanizeKey(value) {
            return humanizeText(value);
        },

        currentCalculationPhaseSignature() {
            return JSON.stringify(this.phaseItems?.saponified_oils ?? []);
        },

        addIngredient(ingredient, requestedPhase = null) {
            const targetPhase = this.resolveTargetPhase(ingredient, requestedPhase);

            if (!targetPhase) {
                return;
            }

            const existingRow = this.phaseItems[targetPhase].find((row) => row.ingredient_id === ingredient.id);

            if (existingRow) {
                return;
            }

            this.phaseItems[targetPhase].push({
                id: `${ingredient.id}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                ingredient_id: ingredient.id,
                name: ingredient.name,
                inci_name: ingredient.inci_name,
                category: ingredient.category,
                soap_inci_naoh_name: ingredient.soap_inci_naoh_name,
                soap_inci_koh_name: ingredient.soap_inci_koh_name,
                koh_sap_value: ingredient.koh_sap_value,
                naoh_sap_value: ingredient.naoh_sap_value,
                fatty_acid_profile: ingredient.fatty_acid_profile ?? {},
                percentage: targetPhase === 'saponified_oils' && this.phaseItems[targetPhase].length === 0 ? 100 : 0,
                note: '',
            });

            if (targetPhase !== 'saponified_oils') {
                this.highlightPostReaction();
            }
        },

        highlightPostReaction() {
            const el = document.getElementById('post-reaction-phases');
            if (!el) return;

            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            el.classList.add('ring-2', 'ring-[var(--color-accent)]', 'ring-offset-2');
            setTimeout(() => {
                el.classList.remove('ring-2', 'ring-[var(--color-accent)]', 'ring-offset-2');
            }, 1200);
        },

        removeIngredient(phaseKey, rowId) {
            this.phaseItems[phaseKey] = this.phaseItems[phaseKey].filter((row) => row.id !== rowId);
        },

        beginRowDrag(phaseKey, rowId, event) {
            this.draggedRowPhaseKey = phaseKey;
            this.draggedRowId = rowId;
            this.dropTargetPhaseKey = phaseKey;
            this.dropTargetRowId = rowId;

            if (event?.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', `${phaseKey}:${rowId}`);
            }
        },

        endRowDrag() {
            this.draggedRowPhaseKey = null;
            this.draggedRowId = null;
            this.dropTargetPhaseKey = null;
            this.dropTargetRowId = null;
        },

        isDraggedRow(phaseKey, rowId) {
            return this.draggedRowPhaseKey === phaseKey && this.draggedRowId === rowId;
        },

        isDropTarget(phaseKey, rowId = null) {
            return this.dropTargetPhaseKey === phaseKey && this.dropTargetRowId === rowId;
        },

        canDropRowInPhase(phaseKey) {
            if (!this.draggedRowPhaseKey || !this.draggedRowId) {
                return false;
            }

            if (phaseKey !== this.draggedRowPhaseKey) {
                return false;
            }

            const draggedRow = (this.phaseItems[this.draggedRowPhaseKey] ?? [])
                .find((row) => row.id === this.draggedRowId);

            if (!draggedRow) {
                return false;
            }

            return !(this.phaseItems[phaseKey] ?? []).some((row) => {
                return row.id !== draggedRow.id
                    && Number(row.ingredient_id) === Number(draggedRow.ingredient_id);
            });
        },

        allowPhaseDrop(phaseKey, event, targetRowId = null) {
            if (!this.canDropRowInPhase(phaseKey)) {
                return;
            }

            event.preventDefault();

            if (event?.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }

            this.dropTargetPhaseKey = phaseKey;
            this.dropTargetRowId = targetRowId;
        },

        dropDraggedRow(phaseKey, event, targetRowId = null) {
            if (!this.canDropRowInPhase(phaseKey)) {
                this.endRowDrag();

                return;
            }

            event.preventDefault();

            const sourcePhaseKey = this.draggedRowPhaseKey;
            const rowId = this.draggedRowId;

            if (!sourcePhaseKey || !rowId) {
                this.endRowDrag();

                return;
            }

            if (sourcePhaseKey !== phaseKey) {
                this.endRowDrag();

                return;
            }

            if (sourcePhaseKey === phaseKey && targetRowId === rowId) {
                this.endRowDrag();

                return;
            }

            const sourceRows = [...(this.phaseItems[sourcePhaseKey] ?? [])];
            const sourceIndex = sourceRows.findIndex((row) => row.id === rowId);

            if (sourceIndex === -1) {
                this.endRowDrag();

                return;
            }

            const [draggedRow] = sourceRows.splice(sourceIndex, 1);
            const targetRows = sourceRows;

            let targetIndex = targetRowId === null
                ? targetRows.length
                : targetRows.findIndex((row) => row.id === targetRowId);

            if (targetIndex === -1) {
                targetIndex = targetRows.length;
            }

            targetRows.splice(targetIndex, 0, draggedRow);

            this.phaseItems[sourcePhaseKey] = sourceRows;
            this.phaseItems[phaseKey] = targetRows;

            this.endRowDrag();
        },

        resolveTargetPhase(ingredient, requestedPhase = null) {
            return resolveIngredientTargetPhase(ingredient, requestedPhase);
        },

        targetPhaseForCategory(category) {
            return getTargetPhaseForCategory(category);
        },
    };
}

/**
 * This section keeps draft/snapshot translation and every Livewire-backed flow
 * in one place so save, preview, and comparison behavior stay consistent.
 */
function createPersistenceSection() {
    return {
        schedulePhaseItemPreviews() {
            const nextCalculationSignature = this.currentCalculationPhaseSignature();
            const shouldRefreshCalculation = nextCalculationSignature !== this.lastCalculationPhaseSignature;

            this.lastCalculationPhaseSignature = nextCalculationSignature;
            this.scheduleLabelingPreview();

            if (shouldRefreshCalculation) {
                this.scheduleCalculationPreview();
            }
        },

        applySnapshot(snapshot, options = {}) {
            const nextState = buildSnapshotStateFromSnapshot(snapshot, this, options);

            if (!nextState) {
                return;
            }

            Object.assign(this, nextState);
            this.lastCalculationPhaseSignature = this.currentCalculationPhaseSignature();
            this.reconcileCostingPrices();
            this.syncIngredientListVariantSelection();
        },

        applyDraft(draft) {
            const nextState = buildDraftStateFromDraft(draft, this);

            if (!nextState) {
                return;
            }

            Object.assign(this, nextState);
            this.lastCalculationPhaseSignature = this.currentCalculationPhaseSignature();
            this.reconcileCostingPrices();
            this.syncIngredientListVariantSelection();
        },

        scheduleCalculationPreview(resetBaseline = false) {
            if (this.calculationPreviewTimer) {
                clearTimeout(this.calculationPreviewTimer);
            }

            this.isPreviewingCalculation = true;
            this.calculationPreviewTimer = setTimeout(() => {
                this.refreshCalculationPreview(resetBaseline);
            }, 120);
        },

        async refreshCalculationPreview(resetBaseline = false) {
            void resetBaseline;

            await refreshWorkbenchCalculationPreview(this);
        },

        scheduleLabelingPreview(resetBaseline = false) {
            void resetBaseline;

            if (this.labelingPreviewTimer) {
                clearTimeout(this.labelingPreviewTimer);
            }

            this.labelingPreviewTimer = setTimeout(() => {
                this.refreshLabelingPreview();
            }, 120);
        },

        async refreshLabelingPreview() {
            await refreshWorkbenchLabelingPreview(this);
        },

        async copyGeneratedIngredientList() {
            if (!this.drySoapOutputListText || !navigator?.clipboard?.writeText) {
                return;
            }

            try {
                await navigator.clipboard.writeText(this.drySoapOutputListText);
                this.inciCopyMessage = 'Copied';
                setTimeout(() => {
                    this.inciCopyMessage = '';
                }, 1600);
            } catch (error) {
                this.inciCopyMessage = 'Copy failed';
            }
        },

        serializeDraft() {
            return buildSerializedDraft(this);
        },

        serializeRow(row) {
            return buildSerializedRow(this, row);
        },

        async saveDraft() {
            await this.persist('saveDraft');
        },

        async saveRecipe() {
            await this.persist('saveRecipe');
        },

        async duplicateFormula() {
            await this.persist('duplicateFormula');
        },

        async persist(method) {
            await persistWorkbench(this, method);
        },
    };
}

export function createRecipeWorkbench(payload) {
    const component = {};

    [
        createRecipeWorkbenchState(payload),
        createCatalogSection(),
        createPersistenceSection(),
        createFormulaSection(),
        createCostingSection(payload),
        createVersionSection(payload),
        createPresentationSection(),
    ].forEach((section) => defineSection(component, section));

    return component;
}
