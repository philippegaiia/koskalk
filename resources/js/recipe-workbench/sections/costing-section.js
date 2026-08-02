import { rowWeightForOilWeight } from '../calculation';
import {
    persistCosting,
    persistPackagingCatalogItem,
} from '../bridge';
import { nonNegativeNumber, number, parseDecimalInput, roundTo } from '../utils';
import { formatDecimalInput } from '../number-format';
import { MASS_UNITS, convertMass, convertMassPrice } from '../mass';

const SYSTEM_PHASE_TRANSLATION_KEYS = {
    saponified_oils: 'costing.phases.saponification',
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
            this.packagingCostRows = (costingPayload?.packaging_items ?? []).map((row) => ({
                id: row.id ?? this.makeLocalPackagingRowId(),
                packaging_item_id: row.packaging_item_id ?? null,
                name: row.name ?? '',
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
            return Object.entries(this.phaseItems).flatMap(([phaseKey, rows]) => rows.map((row, index) => {
                const ingredient = this.ingredientForRow(row);

                return {
                    rowId: row.id,
                    ingredient_id: row.ingredient_id,
                    phaseKey,
                    phaseLabel: this.costingPhaseLabel(phaseKey),
                    position: index + 1,
                    name: row.name,
                    percentage: nonNegativeNumber(row.percentage),
                    weight: rowWeightForOilWeight(this.costingBaseOilWeight, row),
                    weightUnit: this.costingBaseOilUnit,
                    defaultPricePerKg: ingredient?.default_price_per_kg ?? null,
                };
            }));
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

            return this.packagingCostRows.reduce((total, row) => {
                return total + this.packagingBatchCostForRow(row);
            }, 0);
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
