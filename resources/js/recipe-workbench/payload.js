import { normalizedIfraProductCategoryId } from './catalog';
import { rowWeight } from './calculation';
import { nonNegativeNumber } from './utils';

export function serializeRow(state, row) {
    return {
        id: row.id,
        ingredient_id: row.ingredient_id,
        percentage: nonNegativeNumber(row.percentage),
        weight: rowWeight(state, row),
        note: row.note ?? null,
    };
}

export function serializeDraft(state) {
    return {
        name: state.formulaName,
        oil_unit: state.oilUnit,
        oil_weight: state.oilWeight,
        manufacturing_mode: state.manufacturingMode,
        exposure_mode: state.exposureMode,
        regulatory_regime: state.regulatoryRegime,
        editing_mode: state.editMode,
        lye_type: state.lyeType,
        koh_purity_percentage: state.kohPurity,
        dual_lye_koh_percentage: state.dualKohPercentage,
        water_mode: state.waterMode,
        water_value: state.waterValue,
        superfat: state.superfat,
        ifra_product_category_id: normalizedIfraProductCategoryId(state.selectedIfraProductCategoryId),
        phase_items: {
            saponified_oils: state.oilRows.map((row) => serializeRow(state, row)),
            additives: state.additiveRows.map((row) => serializeRow(state, row)),
            fragrance: state.fragranceRows.map((row) => serializeRow(state, row)),
        },
    };
}

export function serializeCosting(state) {
    return {
        oil_weight_for_costing: state.costingOilWeight,
        oil_unit_for_costing: state.costingOilUnit,
        units_produced: state.costingUnitsProduced,
        currency: state.costingCurrency,
        items: state.costingFormulaRows.map((row) => ({
            ingredient_id: row.ingredient_id,
            phase_key: row.phaseKey,
            position: row.position,
            price_per_kg: state.costingPriceForRow(row),
        })),
        packaging_items: state.packagingCostRows.map((row) => ({
            user_packaging_item_id: row.user_packaging_item_id ?? null,
            name: row.name,
            unit_cost: nonNegativeNumber(row.unit_cost),
            components_per_unit: nonNegativeNumber(row.quantity),
        })),
    };
}
