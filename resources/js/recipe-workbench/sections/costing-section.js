import { rowWeightForOilWeight } from '../calculation';
import {
    destroyPackagingCatalogItem,
    persistCosting,
    persistPackagingCatalogItem,
} from '../bridge';
import { nonNegativeNumber, number, roundTo } from '../utils';

const PHASE_LABELS = {
    saponified_oils: 'Reaction core',
    additives: 'Additives',
    fragrance: 'Aromatics',
};

const WEIGHT_FACTORS_IN_KG = {
    g: 0.001,
    kg: 1,
    oz: 0.028349523125,
    lb: 0.45359237,
};

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
            if (!this.hasSavedRecipe) {
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
                    this.costingSaveMessage = response?.message ?? 'The costing data could not be loaded.';

                    return;
                }

                this.applyCostingPayload(response.costing ?? null);
                this.hasLoadedCosting = true;
                this.costingSaveStatus = null;
                this.costingSaveMessage = '';
            } catch (error) {
                this.costingSaveStatus = 'error';
                this.costingSaveMessage = 'The costing data could not be loaded.';
            } finally {
                this.isLoadingCosting = false;
            }
        },

        applyCostingPayload(costingPayload) {
            this.costingId = costingPayload?.settings?.id ?? null;
            this.costingOilWeight = costingPayload?.settings?.oilWeightForCosting ?? this.costingOilWeight ?? null;
            this.costingOilUnit = costingPayload?.settings?.oilUnitForCosting ?? this.costingOilUnit ?? this.oilUnit;
            this.costingUnitsProduced = costingPayload?.settings?.unitsProduced ?? this.costingUnitsProduced ?? null;
            this.costingCurrency = costingPayload?.settings?.currency ?? this.costingCurrency ?? 'EUR';
            this.persistedCostingItemPrices = costingPayload?.item_prices ?? [];
            this.packagingCostRows = (costingPayload?.packaging_items ?? []).map((row) => ({
                id: row.id ?? this.makeLocalPackagingRowId(),
                user_packaging_item_id: row.user_packaging_item_id ?? null,
                name: row.name ?? '',
                unit_cost: row.unit_cost ?? 0,
                quantity: row.quantity ?? 1,
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

                nextPricesByRowId[row.rowId] = persistedPrice ?? row.defaultPricePerKg ?? null;
            });

            this.costingPriceByRowId = nextPricesByRowId;
        },

        costingSignature(ingredientId, phaseKey, position) {
            return `${ingredientId}:${phaseKey}:${position}`;
        },

        get costingBaseOilUnit() {
            return ['g', 'kg', 'oz', 'lb'].includes(this.costingOilUnit) ? this.costingOilUnit : this.oilUnit;
        },

        get costingBaseOilWeight() {
            const overrideWeight = number(this.costingOilWeight);

            return overrideWeight > 0 ? overrideWeight : number(this.oilWeight);
        },

        get costingFormulaRows() {
            return Object.entries(this.phaseItems).flatMap(([phaseKey, rows]) => rows.map((row, index) => {
                const ingredient = this.ingredientForRow(row);

                return {
                    rowId: row.id,
                    ingredient_id: row.ingredient_id,
                    phaseKey,
                    phaseLabel: PHASE_LABELS[phaseKey] ?? phaseKey,
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
            return this.costingPriceByRowId[row.rowId] ?? row.defaultPricePerKg ?? null;
        },

        updateCostingPrice(row, value) {
            const normalizedValue = `${value}`.trim() === ''
                ? null
                : roundTo(nonNegativeNumber(value), 4);

            this.costingPriceByRowId = {
                ...this.costingPriceByRowId,
                [row.rowId]: normalizedValue,
            };

            this.scheduleCostingSave();
        },

        weightInKg(weight, unit = this.costingBaseOilUnit) {
            return nonNegativeNumber(weight) * (WEIGHT_FACTORS_IN_KG[unit] ?? WEIGHT_FACTORS_IN_KG.g);
        },

        lineCostForRow(row) {
            const pricePerKg = number(this.costingPriceForRow(row));

            if (pricePerKg <= 0) {
                return 0;
            }

            return this.weightInKg(row.weight, row.weightUnit) * pricePerKg;
        },

        get ingredientCostTotal() {
            return this.costingFormulaRows.reduce((total, row) => total + this.lineCostForRow(row), 0);
        },

        get packagingCostTotal() {
            return this.packagingCostRows.reduce((total, row) => {
                return total + (nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.quantity));
            }, 0);
        },

        get totalBatchCost() {
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

        get totalBatchWeightKg() {
            const currentOilWeightInKg = this.weightInKg(this.oilWeight, this.oilUnit);
            const costingOilWeightInKg = this.weightInKg(this.costingBaseOilWeight, this.costingBaseOilUnit);

            if (currentOilWeightInKg <= 0) {
                return 0;
            }

            return this.weightInKg(this.finalBatchWeight(), this.oilUnit) * (costingOilWeightInKg / currentOilWeightInKg);
        },

        get costPerKg() {
            return this.totalBatchWeightKg > 0
                ? this.totalBatchCost / this.totalBatchWeightKg
                : 0;
        },

        addPackagingCostRow(packagingItem = null) {
            this.packagingCostRows = [
                ...this.packagingCostRows,
                {
                    id: this.makeLocalPackagingRowId(),
                    user_packaging_item_id: packagingItem?.id ?? null,
                    name: packagingItem?.name ?? '',
                    unit_cost: packagingItem?.unit_cost ?? 0,
                    quantity: this.costingUnitsProducedValue > 0 ? this.costingUnitsProducedValue : 1,
                },
            ];

            this.scheduleCostingSave();
        },

        removePackagingCostRow(rowId) {
            this.packagingCostRows = this.packagingCostRows.filter((row) => row.id !== rowId);
            this.scheduleCostingSave();
        },

        scheduleCostingSave() {
            if (!this.hasSavedRecipe) {
                this.costingSaveStatus = 'warning';
                this.costingSaveMessage = 'Save the first draft before pricing can be kept.';

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
            if (!this.hasSavedRecipe) {
                return;
            }

            await persistCosting(this);

            if (this.costingSaveTimer) {
                clearTimeout(this.costingSaveTimer);
                this.costingSaveTimer = null;
            }
        },

        resetPackagingCatalogForm() {
            this.packagingCatalogForm = {
                id: null,
                name: '',
                unit_cost: '',
                currency: this.costingCurrency ?? 'EUR',
                notes: '',
            };
        },

        editPackagingCatalogItem(item) {
            this.packagingCatalogForm = {
                id: item.id,
                name: item.name,
                unit_cost: item.unit_cost,
                currency: item.currency ?? this.costingCurrency ?? 'EUR',
                notes: item.notes ?? '',
            };
        },

        async savePackagingCatalogItem() {
            if (`${this.packagingCatalogForm.name ?? ''}`.trim() === '') {
                this.packagingCatalogStatus = 'error';
                this.packagingCatalogMessage = 'Packaging items need a name.';

                return;
            }

            const saved = await persistPackagingCatalogItem(this, this.packagingCatalogForm);

            if (saved) {
                this.resetPackagingCatalogForm();
            }
        },

        async deletePackagingCatalogItem(packagingItemId) {
            const removed = await destroyPackagingCatalogItem(this, packagingItemId);

            if (removed && Number(this.packagingCatalogForm.id) === Number(packagingItemId)) {
                this.resetPackagingCatalogForm();
            }
        },

        makeLocalPackagingRowId() {
            return `packaging-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        },
    };
}
