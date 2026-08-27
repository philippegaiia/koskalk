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
    return {
        initializeCostingState() {
            this.applyCostingPayload(payload.costing ?? null);
        },

        async ensureCostingLoaded(force = false) {
            if (!this.hasCurrentFormula) {
                return;
            }

            if ((this.hasLoadedCosting && !force) || this.isLoadingCosting) {
                return;
            }

            this.isLoadingCosting = true;

            try {
                const response = await this.$wire.loadCosting();

                if (!response?.ok) {
                    this.costingSaveStatus = 'error';
                    this.costingSaveMessage = response?.message ?? this.t('costing.messages.load_failed');

                    return;
                }

                this.applyCostingPayload(response.costing ?? null);
                this.hasLoadedCosting = true;
                this.costingSaveStatus = null;
                this.costingSaveMessage = '';
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
                unit_cost: row.unit_cost ?? 0,
                quantity: row.components_per_unit ?? row.quantity ?? 1,
            }));
            this.packagingCatalog = costingPayload?.packaging_catalog ?? this.packagingCatalog ?? [];
            this.reconcileCostingPrices();
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

                const persistedPrice = persistedPricesBySignature.get(
                    this.costingSignature(row.ingredient_id, row.phaseKey, row.position),
                );

                const canonicalPrice = persistedPrice ?? row.defaultPricePerKg ?? null;

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

                    const weight = selectedWeights[lyeType] * scaleRatio;

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
            const weight = convertCostingMass(this.lyeLiquidWaterWeight(), this.oilUnit, this.costingBaseOilUnit)
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

        costingPriceForRow(row) {
            return this.costingPriceByRowId[row.rowId]
                ?? (row.defaultPricePerKg === null
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
                ? 0
                : roundTo(parseDecimalInput(value), 4);

            this.scheduleCostingSave();
        },

        weightInKg(weight, unit = this.costingBaseOilUnit) {
            return convertCostingMass(nonNegativeNumber(weight), unit, 'kg');
        },

        lineCostForRow(row) {
            const displayedPrice = number(this.costingPriceForRow(row));

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
            return this.costingFormulaRows.reduce((total, row) => total + this.lineCostForRow(row), 0);
        },

        get packagingCostTotal() {
            if (this.costingUnitsProducedValue <= 0) {
                return null;
            }

            return this.displayedPackagingCostRows.reduce((total, row) => {
                return total + this.packagingBatchCostForRow(row);
            }, 0);
        },

        /**
         * Before the first save no costing payload exists, so the live packaging
         * plan stands in for the saved costing rows; prices start at zero and
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
                unit_cost: 0,
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
            if (this.packagingCostTotal === null) {
                return null;
            }

            return this.ingredientCostTotal + this.packagingCostTotal;
        },

        get costingUnitsProducedValue() {
            const unitsProduced = Number.parseInt(this.costingUnitsProduced, 10);

            return Number.isInteger(unitsProduced) && unitsProduced > 0 ? unitsProduced : 0;
        },

        get costPerUnit() {
            return this.costingUnitsProducedValue > 0
                ? this.totalBatchCost / this.costingUnitsProducedValue
                : 0;
        },

        packagingCostPerFinishedUnitForRow(row) {
            return nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.quantity);
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
            return this.packagingCostPerFinishedUnitForRow(row) * this.costingUnitsProducedValue;
        },

        scheduleCostingSave() {
            if (!this.hasCurrentFormula) {
                this.costingSaveStatus = 'warning';
                this.costingSaveMessage = this.t('costing.messages.save_product');

                return;
            }

            if (this.costingSaveTimer) {
                clearTimeout(this.costingSaveTimer);
            }

            this.costingSaveTimer = setTimeout(() => {
                this.persistCosting();
            }, 350);
        },

        async persistCosting() {
            if (!this.hasCurrentFormula) {
                return;
            }

            if (this.costingSaveTimer) {
                clearTimeout(this.costingSaveTimer);
                this.costingSaveTimer = null;
            }

            await persistCosting(this, ++this.costingSaveSeq);
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
