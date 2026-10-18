import { rowWeightForOilWeight, lyeBreakdown as buildLyeBreakdown } from '../calculation';
import {
    persistCosting,
    persistPackagingCatalogItem,
} from '../bridge';
import { nonNegativeNumber, number, parseDecimalInput, roundTo } from '../utils';
import { formatDecimalInput } from '../number-format';
import { MASS_UNITS, convertMass, convertMassPrice } from '../mass';

const SYSTEM_PHASE_TRANSLATION_KEYS = {
    saponified_oils: 'costing.phases.saponification',
    lye_water: 'costing.phases.lye_liquid',
    first_dilution: 'costing.phases.first_dilution',
    final_dilution: 'costing.phases.final_dilution',
    additives: 'costing.phases.additions',
    fragrance: 'costing.phases.fragrance',
};

const costingMassUnits = typeof MASS_UNITS === 'undefined' ? ['g', 'kg', 'oz', 'lb'] : MASS_UNITS;
const convertCostingMass = typeof convertMass === 'undefined'
    ? (value) => Number(value) || 0
    : convertMass;
const convertCostingPrice = typeof convertMassPrice === 'undefined'
    ? (value) => Number(value) || 0
    : convertMassPrice;

/**
 * Costing stays derived from the live formula rows, but it keeps its own
 * saved price context so later default-rate changes never rewrite a formula.
 */
export function createCostingSection(payload) {
    const saves = { pending: false, promise: null };
    let savedCostingState = null;

    return {
        get isCostingSimulation() {
            return this.isFormulaLocked;
        },

        get canAdjustCosting() {
            return this.canEditRecipe && !this.isLoadingCosting
                && (this.isCostingSimulation || !this.isEditingUnavailable);
        },

        initializeCostingState() {
            this.applyCostingPayload(payload.costing ?? null);
        },

        async ensureCostingLoaded(force = false) {
            if (saves.pending || saves.promise) {
                return false;
            }
            if (!this.hasCurrentFormula) {
                return;
            }

            if ((this.hasLoadedCosting && !force) || this.isLoadingCosting) {
                return;
            }

            this.isLoadingCosting = true;

            try {
                return await this.queueEditingRead(async () => {
                    if (saves.pending || saves.promise) {
                        return false;
                    }
                    const loadingSequence = this.costingSaveSeq;
                    const response = await this.$wire.loadCosting();

                    if (!response?.ok) {
                        this.costingSaveStatus = 'error';
                        this.costingSaveMessage = response?.message ?? this.t('costing.messages.load_failed');

                        return false;
                    }

                    const editing = response.editing;
                    const formulaChanged = editing && (Number(editing.recipe_revision) !== Number(this.editingRecipeRevision)
                        || editing.current_version_id !== this.editingVersionId);
                    if (formulaChanged || loadingSequence !== this.costingSaveSeq || saves.pending) {
                        this.markEditingStale?.(this.t('editing.stale'), editing);

                        return false;
                    }

                    this.costingPriceByRowId = {};
                    this.applyCostingPayload(response.costing ?? null);
                    if (editing) {
                        this.editingCostingRevision = Number(editing.costing_revision);
                    }
                    this.hasLoadedCosting = true;
                    this.costingSaveStatus = null;
                    this.costingSaveMessage = '';

                    return true;
                });
            } catch (error) {
                this.costingSaveStatus = 'error';
                this.costingSaveMessage = this.t('costing.messages.load_failed');
            } finally {
                this.isLoadingCosting = false;
            }
        },

        applyCostingPayload(costingPayload) {
            this.costingId = costingPayload?.settings?.id ?? null;
            this.costingOilWeight = costingPayload?.settings?.oilWeightForCosting ?? this.costingOilWeight ?? null;
            this.costingOilUnit = costingPayload?.settings?.oilUnitForCosting ?? this.costingOilUnit ?? this.oilUnit;
            this.costingUnitsProduced = costingPayload?.settings?.unitsProduced ?? this.costingUnitsProduced ?? null;
            this.costingCurrency = costingPayload?.settings?.currency ?? this.costingCurrency ?? this.defaultCurrency ?? 'EUR';
            this.persistedCostingItemPrices = costingPayload?.item_prices ?? [];
            this.costingAlkaliIngredients = costingPayload?.alkali_ingredients ?? {};
            this.costingWaterIngredient = costingPayload?.water_ingredient ?? null;
            this.packagingCostRows = (costingPayload?.packaging_items ?? []).map((row) => ({
                id: row.id ?? this.makeLocalPackagingRowId(),
                packaging_item_id: row.packaging_item_id ?? null,
                name: row.name ?? '',
                material_code: row.material_code ?? null,
                unit_cost: Object.hasOwn(row, 'unit_cost') ? row.unit_cost : 0,
                quantity: row.components_per_unit ?? row.quantity ?? 1,
            }));
            this.packagingCatalog = costingPayload?.packaging_catalog ?? this.packagingCatalog ?? [];
            this.reconcileCostingPrices();
            savedCostingState = {
                costingOilWeight: this.costingOilWeight,
                costingOilUnit: this.costingOilUnit,
                costingUnitsProduced: this.costingUnitsProduced,
                costingCurrency: this.costingCurrency,
                costingPriceByRowId: { ...this.costingPriceByRowId },
                packagingCostRows: this.packagingCostRows.map((row) => ({ ...row })),
            };
        },

        resetCostingSimulation() {
            if (!this.isCostingSimulation || !savedCostingState) {
                return;
            }

            Object.assign(this, savedCostingState, {
                costingPriceByRowId: { ...savedCostingState.costingPriceByRowId },
                packagingCostRows: savedCostingState.packagingCostRows.map((row) => ({ ...row })),
            });
            this.costingSaveStatus = null;
            this.costingSaveMessage = '';
        },

        reconcileCostingPrices() {
            const persistedPricesBySignature = new Map(
                (this.persistedCostingItemPrices ?? []).map((row) => [
                    this.costingSignature(row.ingredient_id, row.phase_key, row.position),
                    row.price_per_kg,
                ]),
            );
            const nextPricesByRowId = {};

            this.costingFormulaRows.forEach((row) => {
                if (Object.hasOwn(this.costingPriceByRowId, row.rowId)) {
                    nextPricesByRowId[row.rowId] = this.costingPriceByRowId[row.rowId];

                    return;
                }

                const signature = this.costingSignature(row.ingredient_id, row.phaseKey, row.position);
                const canonicalPrice = persistedPricesBySignature.has(signature)
                    ? persistedPricesBySignature.get(signature)
                    : (row.defaultPricePerKg ?? null);

                nextPricesByRowId[row.rowId] = canonicalPrice === null
                    ? null
                    : this.displayPriceFromPerKg(canonicalPrice);
            });

            this.costingPriceByRowId = nextPricesByRowId;
        },

        costingSignature(ingredientId, phaseKey, position) {
            return `${ingredientId}:${phaseKey}:${position}`;
        },

        get costingBaseOilUnit() {
            return costingMassUnits.includes(this.costingOilUnit) ? this.costingOilUnit : this.oilUnit;
        },

        get costingBaseOilWeight() {
            const overrideWeight = number(this.costingOilWeight);

            return overrideWeight > 0
                ? overrideWeight
                : convertCostingMass(this.oilWeight, this.oilUnit, this.costingBaseOilUnit);
        },

        get costingPriceUnit() {
            return ['oz', 'lb'].includes(this.costingBaseOilUnit) ? 'lb' : 'kg';
        },

        changeCostingUnit(nextUnit) {
            if (!costingMassUnits.includes(nextUnit) || nextUnit === this.costingBaseOilUnit) {
                return;
            }

            const previousPriceUnit = this.costingPriceUnit;
            const overrideWeight = number(this.costingOilWeight);

            if (overrideWeight > 0) {
                this.costingOilWeight = convertCostingMass(
                    overrideWeight,
                    this.costingBaseOilUnit,
                    nextUnit,
                );
            }

            this.costingOilUnit = nextUnit;

            if (previousPriceUnit !== this.costingPriceUnit) {
                this.costingPriceByRowId = Object.fromEntries(
                    Object.entries(this.costingPriceByRowId).map(([rowId, price]) => [
                        rowId,
                        price === null
                            ? null
                            : convertCostingPrice(price, previousPriceUnit, this.costingPriceUnit),
                    ]),
                );
            }

            this.scheduleCostingSave();
        },

        get costingFormulaRows() {
            const rows = [];

            Object.entries(this.phaseItems).forEach(([phaseKey, phaseRows]) => {
                if (phaseKey === 'lye_water') {
                    rows.push(...this.costingImplicitWaterRows());
                }

                rows.push(...phaseRows.map((row, index) => this.costingRowForPhaseItem(phaseKey, row, index)));

                if (phaseKey === 'saponified_oils') {
                    rows.push(...this.costingAlkaliRows());
                }
            });

            return rows;
        },

        costingRowForPhaseItem(phaseKey, row, index) {
            const ingredient = this.ingredientForRow(row);
            const weight = this.costingWeightForRow(phaseKey, row);

            return {
                rowId: row.id,
                ingredient_id: row.ingredient_id,
                phaseKey,
                phaseLabel: this.costingPhaseLabel(phaseKey),
                position: index + 1,
                name: row.name,
                percentage: this.oilBasisPercentage(weight),
                percentageLabel: '%',
                weight,
                weightUnit: this.costingBaseOilUnit,
                defaultPricePerKg: ingredient?.default_price_per_kg ?? null,
                calculated: ['lye_water', 'first_dilution', 'final_dilution'].includes(phaseKey),
            };
        },

        /**
         * Soap prices the calculated alkali (NaOH/KOH) like any other ingredient.
         * The backend persists one costing row per active lye type under the
         * synthetic `lye_alkali` phase; NaOH first keeps dual-lye positions stable.
         */
        costingAlkaliRows() {
            const alkaliIngredients = this.costingAlkaliIngredients ?? {};

            if (this.isCosmeticFormula || Object.keys(alkaliIngredients).length === 0) {
                return [];
            }

            const lyeTypesBySelection = { naoh: ['naoh'], koh: ['koh'], dual: ['naoh', 'koh'] };
            const lyeTypes = lyeTypesBySelection[this.lyeType] ?? ['naoh'];
            const selectedWeights = this.selectedAlkaliWeights();
            const scaleRatio = this.costingScaleRatio();

            return lyeTypes
                .map((lyeType, index) => {
                    const ingredient = alkaliIngredients[lyeType];

                    if (!ingredient || !(selectedWeights[lyeType] > 0)) {
                        return null;
                    }

                    const weight = convertCostingMass(
                        selectedWeights[lyeType],
                        this.oilUnit,
                        this.costingBaseOilUnit,
                    ) * scaleRatio;

                    return {
                        rowId: `${ingredient.ingredient_id}:lye_alkali:${index + 1}`,
                        ingredient_id: ingredient.ingredient_id,
                        phaseKey: 'lye_alkali',
                        phaseLabel: this.t('costing.phases.alkali'),
                        position: index + 1,
                        name: lyeType === 'koh'
                            ? this.t('costing.ingredients.koh_with_purity', {
                                name: ingredient.name,
                                purity: formatDecimalInput(this.kohPurity, this.numberLocale),
                            })
                            : ingredient.name,
                        percentage: this.oilBasisPercentage(weight),
                        percentageLabel: '%',
                        weight,
                        weightUnit: this.costingBaseOilUnit,
                        defaultPricePerKg: ingredient.default_price_per_kg ?? null,
                        calculated: true,
                    };
                })
                .filter(Boolean);
        },

        /**
         * The dilution liquid share not covered by added liquids is plain water.
         * It gets its own priced row so bought water can be costed and own-tap
         * water stays visible at a zero price.
         */
        costingImplicitWaterRows() {
            if (this.isCosmeticFormula) {
                return [];
            }

            const waterIngredient = this.costingWaterIngredient;
            const scaleRatio = this.costingScaleRatio();
            const liquidSoapWaterWeight = typeof this.liquidDilutionWaterWeight === 'function' ? this.liquidDilutionWaterWeight() : 0;
            const weight = convertCostingMass(this.lyeLiquidWaterWeight() + liquidSoapWaterWeight, this.oilUnit, this.costingBaseOilUnit)
                * scaleRatio;

            if (!waterIngredient || !(weight > 0)) {
                return [];
            }

            return [{
                rowId: `${waterIngredient.ingredient_id}:implicit_water:1`,
                ingredient_id: waterIngredient.ingredient_id,
                phaseKey: 'implicit_water',
                phaseLabel: this.t('costing.phases.lye_liquid'),
                position: 1,
                name: waterIngredient.name,
                percentage: this.oilBasisPercentage(weight),
                percentageLabel: '%',
                weight,
                weightUnit: this.costingBaseOilUnit,
                defaultPricePerKg: waterIngredient.default_price_per_kg ?? null,
                calculated: true,
            }];
        },

        /** Percentage of the oil basis so every soap row shares one denominator. */
        oilBasisPercentage(weight) {
            const base = nonNegativeNumber(this.costingBaseOilWeight);

            return base <= 0 ? 0 : nonNegativeNumber(weight) * (100 / base);
        },

        /** Weighed alkali amounts from the server calculation, with the local fallback. */
        selectedAlkaliWeights() {
            const backendLye = this.backendCalculation?.lye ?? null;

            if (backendLye) {
                return {
                    naoh: nonNegativeNumber(backendLye.selected?.naoh_weight),
                    koh: nonNegativeNumber(backendLye.selected?.koh_to_weigh),
                };
            }

            const lye = buildLyeBreakdown(this);

            return {
                naoh: nonNegativeNumber(lye.selected_naoh_weight),
                koh: nonNegativeNumber(lye.koh_to_weigh),
            };
        },

        /** Ratio between the costed oil quantity and the formula's own oil quantity. */
        costingScaleRatio() {
            const formulaOilWeight = convertCostingMass(
                this.oilWeight,
                this.oilUnit,
                this.costingBaseOilUnit,
            );

            if (formulaOilWeight <= 0) {
                return 0;
            }

            return this.costingBaseOilWeight / formulaOilWeight;
        },

        costingWeightForRow(phaseKey, row) {
            if (phaseKey === 'first_dilution' || phaseKey === 'final_dilution') {
                return this.costingDilutionLiquidWeight(phaseKey, row);
            }

            // A liquid soap addition weighs its share of the finished batch.
            if (this.isLiquidSoapFormula && (phaseKey === 'additives' || phaseKey === 'fragrance')) {
                return this.costingScaledFormulaWeight(this.liquidAdditionWeight(row));
            }

            if (phaseKey !== 'lye_water') {
                return rowWeightForOilWeight(this.costingBaseOilWeight, row);
            }

            const formulaOilWeight = convertCostingMass(
                this.oilWeight,
                this.oilUnit,
                this.costingBaseOilUnit,
            );

            if (formulaOilWeight <= 0) {
                return 0;
            }

            const formulaLyeLiquidWeight = convertCostingMass(
                this.lyeLiquidTotalWeight(),
                this.oilUnit,
                this.costingBaseOilUnit,
            );
            const scaledLyeLiquidWeight = formulaLyeLiquidWeight
                * (this.costingBaseOilWeight / formulaOilWeight);

            return scaledLyeLiquidWeight * (nonNegativeNumber(row.percentage) / 100);
        },

        /** A liquid soap dilution liquid weighs what the server preview gave it, scaled to the costing batch. */
        costingDilutionLiquidWeight(phaseKey, row) {
            const liquid = (this.liquidProcessPreview?.dilution_liquids?.[phaseKey]?.liquids ?? [])
                .find((candidate) => candidate.id === row.id || Number(candidate.ingredient_id) === Number(row.ingredient_id));

            return liquid ? this.costingScaledFormulaWeight(nonNegativeNumber(liquid.weight)) : 0;
        },

        /** A formula weight in the formula's unit, scaled to the costing batch. */
        costingScaledFormulaWeight(weight) {
            const formulaOilWeight = convertCostingMass(this.oilWeight, this.oilUnit, this.costingBaseOilUnit);

            return formulaOilWeight > 0
                ? convertCostingMass(nonNegativeNumber(weight), this.oilUnit, this.costingBaseOilUnit) * (this.costingBaseOilWeight / formulaOilWeight)
                : 0;
        },

        costingPriceForRow(row) {
            return Object.hasOwn(this.costingPriceByRowId, row.rowId)
                ? this.costingPriceByRowId[row.rowId]
                : (row.defaultPricePerKg === null
                    ? null
                    : this.displayPriceFromPerKg(row.defaultPricePerKg));
        },

        displayPriceFromPerKg(pricePerKilogram) {
            return convertCostingPrice(pricePerKilogram, 'kg', this.costingPriceUnit);
        },

        canonicalPricePerKg(row) {
            const displayedPrice = this.costingPriceForRow(row);

            return displayedPrice === null
                ? null
                : convertCostingPrice(displayedPrice, this.costingPriceUnit, 'kg');
        },

        costingPhaseLabel(phaseKey) {
            const authoredPhaseName = this.phaseOrder.find((phase) => phase.key === phaseKey)?.name;

            if (this.isCosmeticFormula) {
                return authoredPhaseName ?? phaseKey;
            }

            const translationKey = SYSTEM_PHASE_TRANSLATION_KEYS[phaseKey];

            return translationKey ? this.t(translationKey) : (authoredPhaseName ?? phaseKey);
        },

        updateCostingOilWeight(event) {
            const rawValue = `${event.target.value ?? ''}`.trim();

            if (rawValue === '') {
                this.costingOilWeight = null;
                event.target.value = '';
                this.scheduleCostingSave();

                return;
            }

            this.costingOilWeight = Math.max(0, parseDecimalInput(rawValue));
            event.target.value = this.format(this.costingOilWeight, 2);
            this.scheduleCostingSave();
        },

        updateCostingPrice(row, value) {
            const normalizedValue = `${value}`.trim() === ''
                ? null
                : roundTo(parseDecimalInput(value), 4);

            this.costingPriceByRowId = {
                ...this.costingPriceByRowId,
                [row.rowId]: normalizedValue,
            };

            this.scheduleCostingSave();
        },

        updatePackagingUnitCost(row, value) {
            row.unit_cost = `${value}`.trim() === ''
                ? null
                : roundTo(parseDecimalInput(value), 4);

            this.scheduleCostingSave();
        },

        weightInKg(weight, unit = this.costingBaseOilUnit) {
            return convertCostingMass(nonNegativeNumber(weight), unit, 'kg');
        },

        /**
         * Lye and dilution-liquid quantities come from the soap calculation;
         * when the server rejects the settings they are not usable amounts.
         */
        costingRowIsMasked(row) {
            return Boolean(row?.calculated) && this.isCalculationRejected;
        },

        costingRowWeightLabel(row) {
            return this.costingRowIsMasked(row) ? '—' : this.format(row.weight, 2);
        },

        costingLineCostLabel(row) {
            if (this.costingRowIsMasked(row)) {
                return '—';
            }

            const lineCost = this.lineCostForRow(row);

            return lineCost === null ? '' : this.format(lineCost, 2);
        },

        lineCostForRow(row) {
            if (this.costingRowIsMasked(row)) {
                return null;
            }

            const price = this.costingPriceForRow(row);

            if (!this.hasKnownCostingPrice(price)) {
                return null;
            }

            const displayedPrice = number(price);

            if (displayedPrice <= 0) {
                return 0;
            }

            const quantityInPriceUnit = convertCostingMass(
                row.weight,
                row.weightUnit,
                this.costingPriceUnit,
            );

            return quantityInPriceUnit * displayedPrice;
        },

        get ingredientCostTotal() {
            if (this.costingFormulaRows.some((row) => this.costingRowIsMasked(row))) {
                return null;
            }

            return this.costingFormulaRows.reduce((total, row) => {
                const lineCost = this.lineCostForRow(row);

                return lineCost === null ? total : total + lineCost;
            }, 0);
        },

        get packagingCostTotal() {
            const rows = this.displayedPackagingCostRows;

            if (rows.length === 0) {
                return 0;
            }

            if (this.costingUnitsProducedValue <= 0) {
                return null;
            }

            return rows.reduce((total, row) => {
                const batchCost = this.packagingBatchCostForRow(row);

                return batchCost === null ? total : total + batchCost;
            }, 0);
        },

        get hasUnpricedIngredientRows() {
            return this.costingFormulaRows.some((row) => !this.hasKnownCostingPrice(this.costingPriceForRow(row)));
        },

        get hasUnpricedPackagingRows() {
            return this.displayedPackagingCostRows.some((row) => !this.hasKnownPackagingUnitCost(row));
        },

        get hasUnpricedCostingRows() {
            return this.hasUnpricedIngredientRows || this.hasUnpricedPackagingRows;
        },

        /**
         * Before the first save no costing payload exists, so the live packaging
         * plan stands in for the saved costing rows; prices start unpriced and
         * persistence stays blocked by the existing "save product first" guard.
         */
        get displayedPackagingCostRows() {
            if (this.packagingCostRows.length > 0 || this.isLoadingCosting || this.hasLoadedCosting) {
                return this.packagingCostRows;
            }

            return (this.packagingPlanRows ?? []).map((row) => ({
                id: row.id,
                packaging_item_id: row.packaging_item_id ?? null,
                name: row.name ?? '',
                material_code: row.material_code ?? null,
                unit_cost: null,
                quantity: nonNegativeNumber(row.components_per_unit),
                isUnsavedPlanRow: true,
            }));
        },

        get packagingCostPerFinishedUnitTotal() {
            return this.packagingCostRows.reduce((total, row) => {
                return total + (nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.quantity));
            }, 0);
        },

        get totalBatchCost() {
            if (this.ingredientCostTotal === null || this.packagingCostTotal === null) {
                return null;
            }

            return this.ingredientCostTotal + this.packagingCostTotal;
        },

        get costingUnitsProducedValue() {
            const unitsProduced = parseDecimalInput(this.costingUnitsProduced);

            return Number.isFinite(unitsProduced) && unitsProduced > 0 ? unitsProduced : 0;
        },

        get costPerUnit() {
            if (this.costingUnitsProducedValue <= 0) {
                return 0;
            }

            return this.totalBatchCost === null
                ? null
                : this.totalBatchCost / this.costingUnitsProducedValue;
        },

        packagingCostPerFinishedUnitForRow(row) {
            if (!this.hasKnownPackagingUnitCost(row)) {
                return null;
            }

            return nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.quantity);
        },

        hasKnownPackagingUnitCost(row) {
            return row !== null
                && typeof row === 'object'
                && Object.hasOwn(row, 'unit_cost')
                && this.hasKnownCostingPrice(row.unit_cost);
        },

        hasKnownCostingPrice(price) {
            return price !== null && price !== '' && price !== undefined;
        },

        /** Packaging counts default to whole units; decimals appear only when present. */
        formatPackagingQuantity(value) {
            const quantity = number(value);

            if (!Number.isFinite(quantity)) {
                return '';
            }

            return Number.isInteger(quantity)
                ? this.format(quantity, 0)
                : this.format(quantity, 3);
        },

        packagingBatchCostForRow(row) {
            const costPerUnit = this.packagingCostPerFinishedUnitForRow(row);

            return costPerUnit === null
                ? null
                : costPerUnit * this.costingUnitsProducedValue;
        },

        scheduleCostingSave() {
            if (this.isCostingSimulation) {
                this.costingSaveSeq++;

                return;
            }

            if (!this.hasCurrentFormula) {
                this.costingSaveStatus = 'warning';
                this.costingSaveMessage = this.t('costing.messages.save_product');

                return;
            }

            this.costingSaveSeq++;
            saves.pending = true;
            this.dirtyStateRegistry?.set('recipe-costing', 'dirty');

            if (this.costingSaveTimer) {
                clearTimeout(this.costingSaveTimer);
            }

            this.costingSaveTimer = setTimeout(() => {
                this.flushCostingSave();
            }, 350);
        },

        async persistCosting() {
            if (this.isCostingSimulation || !this.hasCurrentFormula) {
                return false;
            }

            saves.pending = true;

            return this.flushCostingSave();
        },

        async flushCostingSave() {
            if (this.costingSaveTimer) {
                clearTimeout(this.costingSaveTimer);
                this.costingSaveTimer = null;
            }

            if (this.isCostingSimulation) {
                saves.pending = false;
                this.dirtyStateRegistry?.remove('recipe-costing');

                return true;
            }

            if (saves.promise) {
                return saves.promise;
            }

            if (!saves.pending) {
                return this.costingSaveStatus !== 'error';
            }

            saves.promise = (async () => {
                while (saves.pending) {
                    this.dirtyStateRegistry?.set('recipe-costing', 'saving');
                    let response;
                    try {
                        response = await this.queueRevisionMutation(() => {
                            if (this.costingSaveTimer) {
                                clearTimeout(this.costingSaveTimer);
                                this.costingSaveTimer = null;
                            }
                            saves.pending = false;

                            return persistCosting(this, this.costingSaveSeq);
                        });
                    } catch (error) {
                        response = { ok: false };
                    }

                    if (!response?.ok) {
                        saves.pending = true;
                        this.costingSaveStatus = 'error';
                        this.costingSaveMessage = response?.message ?? this.t('costing.messages.save_failed');
                        this.dirtyStateRegistry?.set('recipe-costing', 'failed');

                        return false;
                    }
                }

                this.dirtyStateRegistry?.set('recipe-costing', 'saved');

                return true;
            })();

            try {
                return await saves.promise;
            } finally {
                saves.promise = null;
            }
        },

        resetPackagingCatalogForm() {
            this.packagingCatalogForm = {
                id: null,
                name: '',
                material_code: '',
                unit_cost: '',
                currency: this.costingCurrency ?? this.defaultCurrency ?? 'EUR',
                notes: '',
            };
        },

        openPackagingCatalogModal() {
            this.packagingCatalogStatus = null;
            this.packagingCatalogMessage = '';
            this.resetPackagingCatalogForm();

            this.packagingCatalogModalOpen = true;
        },

        closePackagingCatalogModal(preserveFeedback = false) {
            this.packagingCatalogModalOpen = false;

            if (!preserveFeedback) {
                this.packagingCatalogStatus = null;
                this.packagingCatalogMessage = '';
            }

            this.resetPackagingCatalogForm();
        },

        async savePackagingCatalogItem(addToPlan = false) {
            if (`${this.packagingCatalogForm.name ?? ''}`.trim() === '') {
                this.packagingCatalogStatus = 'error';
                this.packagingCatalogMessage = this.t('packaging.messages.name_required');

                return null;
            }

            const saved = await persistPackagingCatalogItem(this, this.packagingCatalogForm);

            if (saved) {
                if (addToPlan) {
                    this.addPackagingPlanRow(saved);
                }

                this.closePackagingCatalogModal(true);
            }

            return saved;
        },

        async savePackagingCatalogItemAndAdd() {
            return this.savePackagingCatalogItem(true);
        },

        async savePackagingCatalogItemOnly() {
            return this.savePackagingCatalogItem(false);
        },

        normalizeDecimalBlur(event, allowNegative = false) {
            const raw = `${event.target.value ?? ''}`.trim();

            if (raw === '') {
                event.target.value = '';

                return;
            }

            const parsed = parseDecimalInput(raw);
            const normalized = allowNegative ? parsed : Math.max(0, parsed);

            event.target.value = formatDecimalInput(normalized, this.numberLocale);
            event.target.dispatchEvent(new Event('input', { bubbles: true }));
        },
    };
}
