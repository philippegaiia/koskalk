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
import { resolveNumberLocale } from './number-format';
import { createFormulaSection } from './sections/formula-section';
import { createPackagingSection } from './sections/packaging-section';
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

function defaultPhaseBlueprints(productFamilySlug) {
    if (productFamilySlug === 'cosmetic') {
        return [{ key: 'phase_a', name: 'Phase A' }];
    }

    return [
        { key: 'saponified_oils', name: 'Saponified Oils' },
        { key: 'additives', name: 'Additives' },
        { key: 'fragrance', name: 'Fragrance And Aromatics' },
    ];
}

function phaseItemsForBlueprints(blueprints) {
    return blueprints.reduce((items, phase) => {
        items[phase.key] = [];

        return items;
    }, {});
}

const FORMULA_DIAGNOSTICS_PREFERENCE_KEY = 'soapkraft.formulaDiagnosticsDetailsOpen';
const NUMBER_LOCALE_PREFERENCE_KEY = 'soapkraft.numberLocale';

function storedNumberLocale() {
    try {
        return window.localStorage.getItem(NUMBER_LOCALE_PREFERENCE_KEY);
    } catch (error) {
        void error;

        return null;
    }
}

function resolvedNumberLocale(preferredLocale, numberLocaleOptions) {
    const supportedLocales = Object.keys(numberLocaleOptions);

    if (typeof resolveNumberLocale !== 'function') {
        return preferredLocale ?? supportedLocales[0] ?? 'en_US';
    }

    const browserLanguages = typeof navigator === 'undefined'
        ? []
        : (navigator.languages ?? [navigator.language]);

    return resolveNumberLocale(
        preferredLocale,
        supportedLocales,
        browserLanguages,
        storedNumberLocale(),
    );
}

function preferredFormulaDiagnosticsOpen() {
    try {
        const storedPreference = window.localStorage.getItem(FORMULA_DIAGNOSTICS_PREFERENCE_KEY);

        if (storedPreference !== null) {
            return storedPreference === 'true';
        }
    } catch (error) {
        void error;
    }

    return false;
}

function canMoveSoapRowToPhase(row, sourcePhaseKey, targetPhaseKey) {
    if (sourcePhaseKey === targetPhaseKey) {
        return true;
    }

    const oilAdditivePhases = ['saponified_oils', 'additives'];

    return row?.category === 'carrier_oil'
        && oilAdditivePhases.includes(sourcePhaseKey)
        && oilAdditivePhases.includes(targetPhaseKey);
}

/**
 * This section owns the public state keys and the boot-time watchers that keep
 * the preview in sync while the user edits the workbench.
 */
function createRecipeWorkbenchState(payload) {
    const initialSnapshot = payload.savedSnapshot ?? null;
    const initialDraft = payload.savedDraft ?? initialSnapshot?.draft ?? null;
    const productFamilySlug = payload.productFamily?.slug ?? 'soap';
    const isCosmeticFormula = productFamilySlug === 'cosmetic';
    const phaseOrder = Array.isArray(payload.phases) && payload.phases.length > 0
        ? payload.phases
        : defaultPhaseBlueprints(productFamilySlug);
    const numberLocaleOptions = payload.numberLocaleOptions ?? {};
    const numberLocale = resolvedNumberLocale(payload.numberLocale, numberLocaleOptions);

    return {
        activeWorkbenchTab: window.location.hash.replace('#', '') || 'formula',
        recipeId: payload.recipe?.id ?? null,
        canPersist: Boolean(payload.canPersist ?? false),
        numberLocale,
        numberLocaleOptions,
        productFamilySlug,
        productTypes: payload.productTypes ?? [],
        initialProductType: payload.productType ?? null,
        productTypeId: payload.productType?.id === null || payload.productType?.id === undefined ? '' : String(payload.productType.id),
        currentVersionId: payload.recipe?.current_version_id ?? null,
        currentVersionNumber: payload.recipe?.version_number ?? null,
        currentVersionIsDraft: payload.recipe?.is_current ?? true,
        isFormulaLocked: Boolean(payload.recipe?.is_locked ?? false),
        formulaName: isCosmeticFormula ? 'New Cosmetic Formula' : 'New Soap Formula',
        oilUnit: 'g',
        oilWeight: isCosmeticFormula ? 100 : 1000,
        manufacturingMode: isCosmeticFormula ? 'blend_only' : 'saponify_in_formula',
        exposureMode: isCosmeticFormula ? 'leave_on' : 'rinse_off',
        regulatoryRegime: 'eu',
        editMode: 'percentage',
        lyeType: 'naoh',
        kohPurity: 90,
        dualKohPercentage: 40,
        waterMode: 'percent_of_oils',
        waterValue: 38,
        superfat: 5,
        search: '',
        activeCategory: isCosmeticFormula ? 'all' : 'carrier_oil',
        isComplianceSettingsOpen: false,
        isFormulaSettingsOpen: initialDraft === null,
        formulaDiagnosticsPreferenceKey: FORMULA_DIAGNOSTICS_PREFERENCE_KEY,
        isFormulaDiagnosticsOpen: preferredFormulaDiagnosticsOpen(),
        ifraProductCategories: payload.ifraProductCategories ?? [],
        regulatoryRegimes: payload.regulatoryRegimes?.length ? payload.regulatoryRegimes : [{ code: 'eu', name: 'EU regime', version_label: null, status: 'active', allergen_rule_count: 0, substance_rule_count: 0 }],
        selectedIfraProductCategoryId: payload.defaultIfraProductCategoryId === null || payload.defaultIfraProductCategoryId === undefined ? '' : String(payload.defaultIfraProductCategoryId),
        finalIngredientList: '',
        finalIngredientListBasisHash: '',
        finalPlainIngredientList: '',
        finalPlainIngredientListBasisHash: '',
        ingredients: payload.ingredients ?? [],
        backendCalculation: initialSnapshot?.calculation ?? null,
        backendLabeling: initialSnapshot?.labeling ?? null,
        backendRestrictions: initialSnapshot?.restrictions ?? null,
        catalogReview: initialDraft?.catalogReview ?? null,
        selectedIngredientListVariantKey: initialSnapshot?.labeling?.default_variant_key ?? (isCosmeticFormula ? 'incorporated_ingredients' : 'saponified_with_superfat'),
        savedRecipeUrl: payload.recipe?.saved_formula_url ?? null,
        inciCopyMessage: '',
        calculationPreviewMessage: '',
        calculationPreviewTimer: null,
        labelingPreviewTimer: null,
        isPreviewingCalculation: false,
        draggedRowId: null,
        draggedRowPhaseKey: null,
        dropTargetPhaseKey: null,
        dropTargetRowId: null,
        phaseOrder,
        saveStatus: null,
        saveMessage: '',
        isSaving: false,
        costingId: payload.costing?.settings?.id ?? null,
        costingOilWeight: payload.costing?.settings?.oilWeightForCosting ?? null,
        costingOilUnit: payload.costing?.settings?.oilUnitForCosting ?? null,
        costingUnitsProduced: payload.costing?.settings?.unitsProduced ?? null,
        costingCurrency: payload.costing?.settings?.currency ?? payload.defaultCurrency ?? 'EUR',
        persistedCostingItemPrices: payload.costing?.item_prices ?? [],
        costingPriceByRowId: {},
        packagingPlanRows: (payload.packagingItems ?? []).map((row) => ({
            id: row.id ?? `packaging-plan-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            user_packaging_item_id: row.user_packaging_item_id ?? null,
            name: row.name ?? '',
            components_per_unit: row.components_per_unit ?? 1,
            notes: row.notes ?? '',
        })),
        packagingCostRows: [],
        packagingCatalog: payload.packagingCatalog ?? payload.costing?.packaging_catalog ?? [],
        packagingCatalogSearch: '',
        packagingCatalogSelectOpen: false,
        packagingCatalogForm: {
            id: null,
            name: '',
            unit_cost: '',
            currency: payload.costing?.settings?.currency ?? payload.defaultCurrency ?? 'EUR',
            notes: '',
        },
        packagingCatalogModalOpen: false,
        hasLoadedCosting: Boolean(payload.costingLoaded ?? payload.costing),
        defaultCurrency: payload.defaultCurrency ?? 'EUR',
        isLoadingCosting: false,
        costingSaveTimer: null,
        costingSaveSeq: 0,
        isSavingCosting: false,
        costingSaveStatus: null,
        costingSaveMessage: '',
        packagingCatalogStatus: null,
        packagingCatalogMessage: '',
        lastCalculationPhaseSignature: null,
        dirtyBaselineSignature: null,
        unsavedBeforeUnloadHandler: null,
        unsavedNavigateHandler: null,
        lastAddedIngredientRowId: null,
        phaseItems: phaseItemsForBlueprints(phaseOrder),

        init() {
            this.applyDraft(initialDraft);
            this.applySnapshot(initialSnapshot);
            this.initializeCostingState();
            this.resetPackagingCatalogForm();
            this.refreshDirtyBaseline();
            if (this.canPersist) {
                this.installUnsavedChangesGuard();
            }

            this.$watch('isFormulaDiagnosticsOpen', (isOpen) => {
                this.persistFormulaDiagnosticsPreference(isOpen);
            });

            if (this.activeWorkbenchTab === 'costing') {
                this.ensureCostingLoaded();
            }

            this.$watch('activeWorkbenchTab', (tab) => {
                if (tab === 'costing') {
                    this.ensureCostingLoaded();
                }
            });

            if (!this.isCosmeticFormula && this.phaseItems.saponified_oils.length === 0) {
                const defaultOil = this.filteredIngredients.find((ingredient) => ingredient.can_add_to_saponified_oils);

                if (defaultOil) {
                    this.addIngredient(defaultOil, 'saponified_oils', false);
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

        destroy() {
            this.removeUnsavedChangesGuard();
        },

        toggleFormulaDiagnostics() {
            this.isFormulaDiagnosticsOpen = !this.isFormulaDiagnosticsOpen;
        },

        toggleFormulaSettings() {
            this.isFormulaSettingsOpen = !this.isFormulaSettingsOpen;
        },

        persistFormulaDiagnosticsPreference(isOpen) {
            try {
                window.localStorage.setItem(this.formulaDiagnosticsPreferenceKey, isOpen ? 'true' : 'false');
            } catch (error) {
                void error;
            }
        },

        persistNumberLocale() {
            try {
                window.localStorage.setItem(NUMBER_LOCALE_PREFERENCE_KEY, this.numberLocale);
            } catch (error) {
                void error;
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

        get selectedProductType() {
            const selectedId = Number(this.productTypeId);

            if (!Number.isFinite(selectedId) || selectedId <= 0) {
                return null;
            }

            return this.productTypes.find((productType) => Number(productType.id) === selectedId)
                ?? (Number(this.initialProductType?.id) === selectedId ? this.initialProductType : null);
        },

        get productTypeName() {
            return this.selectedProductType?.name ?? null;
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

        categoryIngredientCount(category) {
            if (category === 'all') {
                return this.ingredients.length;
            }

            return this.ingredients.filter((ingredient) => ingredient.category === category).length;
        },

        fattyAcidLabels() {
            return buildFattyAcidLabels();
        },

        humanizeKey(value) {
            return humanizeText(value);
        },

        currentCalculationPhaseSignature() {
            if (this.isCosmeticFormula) {
                return JSON.stringify({
                    oilWeight: this.oilWeight,
                    phaseItems: this.phaseItems ?? {},
                });
            }

            return JSON.stringify(this.phaseItems?.saponified_oils ?? []);
        },

        addIngredient(ingredient, requestedPhase = null, shouldAnimate = true) {
            const targetPhase = this.resolveTargetPhase(ingredient, requestedPhase);

            if (!targetPhase) {
                return;
            }

            if (!Array.isArray(this.phaseItems[targetPhase])) {
                this.phaseItems[targetPhase] = [];
            }

            const existingRow = this.phaseItems[targetPhase].find((row) => row.ingredient_id === ingredient.id);

            if (existingRow) {
                return;
            }

            const nextRow = {
                id: `${ingredient.id}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                ingredient_id: ingredient.id,
                name: ingredient.name,
                is_user_owned: Boolean(ingredient.is_user_owned),
                inci_name: ingredient.inci_name,
                category: ingredient.category,
                soap_inci_naoh_name: ingredient.soap_inci_naoh_name,
                soap_inci_koh_name: ingredient.soap_inci_koh_name,
                koh_sap_value: ingredient.koh_sap_value,
                naoh_sap_value: ingredient.naoh_sap_value,
                fatty_acid_profile: ingredient.fatty_acid_profile ?? {},
                percentage: (targetPhase === 'saponified_oils' && this.phaseItems[targetPhase].length === 0)
                    || (this.isCosmeticFormula && this.cosmeticFormulaRows().length === 0)
                    ? 100
                    : 0,
                note: '',
            };

            this.phaseItems[targetPhase].push(nextRow);

            if (shouldAnimate) {
                this.lastAddedIngredientRowId = nextRow.id;
            }

            if (this.isCosmeticFormula) {
                this.highlightCosmeticPhase(targetPhase);
            } else if (targetPhase !== 'saponified_oils') {
                this.highlightPostReaction();
            }
        },

        prefersReducedMotion() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        animateAddedIngredientRow(element, rowId) {
            if (!element || rowId !== this.lastAddedIngredientRowId) {
                return;
            }

            if (element.dataset.addedIngredientAnimation === rowId) {
                return;
            }

            element.dataset.addedIngredientAnimation = rowId;

            if (this.prefersReducedMotion() || typeof element.animate !== 'function') {
                return;
            }

            element.animate([
                {
                    opacity: 0.78,
                    transform: 'translateY(-6px) scale(0.992)',
                    backgroundColor: 'color-mix(in oklab, var(--color-accent-soft) 52%, white)',
                },
                {
                    opacity: 1,
                    transform: 'translateY(0) scale(1)',
                    backgroundColor: 'white',
                },
            ], {
                duration: 240,
                easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
            });
        },

        highlightPostReaction() {
            const el = document.getElementById('post-reaction-phases');

            this.highlightFormulaTarget(el);
        },

        highlightCosmeticPhase(phaseKey) {
            const el = document.getElementById(`cosmetic-phase-${phaseKey}`);

            this.highlightFormulaTarget(el);
        },

        highlightFormulaTarget(el) {
            if (!el) {
                return;
            }

            el.scrollIntoView({ behavior: this.prefersReducedMotion() ? 'auto' : 'smooth', block: 'nearest' });
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

        autoScrollDuringRowDrag(event) {
            if (!this.draggedRowPhaseKey || !this.draggedRowId || typeof window === 'undefined') {
                return;
            }

            const viewportHeight = window.innerHeight
                || document.documentElement?.clientHeight
                || 0;

            if (!viewportHeight || typeof event?.clientY !== 'number') {
                return;
            }

            const edgeSize = Math.min(120, Math.max(72, viewportHeight * 0.16));
            const maxStep = 28;
            let scrollStep = 0;

            if (event.clientY < edgeSize) {
                scrollStep = -Math.ceil(((edgeSize - event.clientY) / edgeSize) * maxStep);
            } else if (event.clientY > viewportHeight - edgeSize) {
                scrollStep = Math.ceil(((event.clientY - (viewportHeight - edgeSize)) / edgeSize) * maxStep);
            }

            if (scrollStep !== 0 && typeof window.scrollBy === 'function') {
                window.scrollBy({ top: scrollStep, behavior: 'auto' });
            }
        },

        isDraggedRow(phaseKey, rowId) {
            return this.draggedRowPhaseKey === phaseKey && this.draggedRowId === rowId;
        },

        isDropTarget(phaseKey, rowId = null) {
            if (this.dropTargetPhaseKey !== phaseKey) {
                return false;
            }

            if (this.dropTargetRowId === rowId) {
                return true;
            }

            return rowId !== null
                && this.dropTargetRowId === null
                && this.rowIsLastDropTarget(phaseKey, rowId);
        },

        rowIsLastDropTarget(phaseKey, rowId) {
            const rows = this.phaseItems[phaseKey] ?? [];

            return rows.length > 0 && rows[rows.length - 1]?.id === rowId;
        },

        resolvedDropTargetRowId(phaseKey, event, targetRowId = null) {
            if (targetRowId === null || !this.rowIsLastDropTarget(phaseKey, targetRowId)) {
                return targetRowId;
            }

            const rect = event?.currentTarget?.getBoundingClientRect?.();

            if (!rect) {
                return targetRowId;
            }

            return event.clientY >= rect.top + (rect.height / 2)
                ? null
                : targetRowId;
        },

        canDropRowInPhase(phaseKey) {
            if (!this.draggedRowPhaseKey || !this.draggedRowId) {
                return false;
            }

            const draggedRow = (this.phaseItems[this.draggedRowPhaseKey] ?? [])
                .find((row) => row.id === this.draggedRowId);

            if (!draggedRow) {
                return false;
            }

            if (!this.isCosmeticFormula && !canMoveSoapRowToPhase(draggedRow, this.draggedRowPhaseKey, phaseKey)) {
                return false;
            }

            return !(this.phaseItems[phaseKey] ?? []).some((row) => {
                return row.id !== draggedRow.id
                    && Number(row.ingredient_id) === Number(draggedRow.ingredient_id);
            });
        },

        allowPhaseDrop(phaseKey, event, targetRowId = null) {
            this.autoScrollDuringRowDrag(event);

            if (!this.canDropRowInPhase(phaseKey)) {
                return;
            }

            event.preventDefault();

            if (event?.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }

            this.dropTargetPhaseKey = phaseKey;
            this.dropTargetRowId = this.resolvedDropTargetRowId(phaseKey, event, targetRowId);
        },

        dropDraggedRow(phaseKey, event, targetRowId = null) {
            if (!this.canDropRowInPhase(phaseKey)) {
                this.endRowDrag();

                return;
            }

            event.preventDefault();

            const sourcePhaseKey = this.draggedRowPhaseKey;
            const rowId = this.draggedRowId;
            const resolvedTargetRowId = this.resolvedDropTargetRowId(phaseKey, event, targetRowId);

            if (!sourcePhaseKey || !rowId) {
                this.endRowDrag();

                return;
            }

            if (sourcePhaseKey === phaseKey && resolvedTargetRowId === rowId) {
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
            const targetRows = sourcePhaseKey === phaseKey
                ? sourceRows
                : [...(this.phaseItems[phaseKey] ?? [])];

            let targetIndex = resolvedTargetRowId === null
                ? targetRows.length
                : targetRows.findIndex((row) => row.id === resolvedTargetRowId);

            if (targetIndex === -1) {
                targetIndex = targetRows.length;
            }

            targetRows.splice(targetIndex, 0, draggedRow);

            this.phaseItems[sourcePhaseKey] = sourceRows;
            this.phaseItems[phaseKey] = targetRows;

            this.endRowDrag();
        },

        resolveTargetPhase(ingredient, requestedPhase = null) {
            if (this.isCosmeticFormula) {
                return requestedPhase && Array.isArray(this.phaseItems[requestedPhase])
                    ? requestedPhase
                    : this.cosmeticDefaultPhaseKey();
            }

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

        serializeDraftJson() {
            return JSON.stringify(this.serializeDraft());
        },

        currentDirtySignature() {
            return this.serializeDraftJson();
        },

        refreshDirtyBaseline() {
            this.dirtyBaselineSignature = this.currentDirtySignature();
        },

        hasUnsavedWorkbenchChanges() {
            return this.dirtyBaselineSignature !== null
                && this.currentDirtySignature() !== this.dirtyBaselineSignature;
        },

        installUnsavedChangesGuard() {
            if (this.unsavedBeforeUnloadHandler || this.unsavedNavigateHandler) {
                return;
            }

            this.unsavedBeforeUnloadHandler = (event) => {
                if (!this.hasUnsavedWorkbenchChanges() || this.isSaving) {
                    return;
                }

                event.preventDefault();
                event.returnValue = '';

                return '';
            };

            this.unsavedNavigateHandler = (event) => {
                if (!this.hasUnsavedWorkbenchChanges() || this.isSaving) {
                    return;
                }

                if (!window.confirm('You have unsaved formula changes. Leave without saving?')) {
                    event.preventDefault();
                }
            };

            window.addEventListener('beforeunload', this.unsavedBeforeUnloadHandler);
            document.addEventListener('livewire:navigate', this.unsavedNavigateHandler);
        },

        removeUnsavedChangesGuard() {
            if (this.unsavedBeforeUnloadHandler) {
                window.removeEventListener('beforeunload', this.unsavedBeforeUnloadHandler);
                this.unsavedBeforeUnloadHandler = null;
            }

            if (this.unsavedNavigateHandler) {
                document.removeEventListener('livewire:navigate', this.unsavedNavigateHandler);
                this.unsavedNavigateHandler = null;
            }
        },

        serializeRow(row) {
            return buildSerializedRow(this, row);
        },

        async save() {
            await this.persist('save');
        },

        async publish() {
            await this.persist('publish');
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
        createPackagingSection(),
        createCostingSection(payload),
        createVersionSection(payload),
        createPresentationSection(),
    ].forEach((section) => defineSection(component, section));

    return component;
}
