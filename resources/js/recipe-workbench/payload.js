import { normalizedIfraProductCategoryId } from './catalog';
import { rowWeight, lyeBreakdown as buildLyeBreakdown } from './calculation';
import { nonNegativeNumber, number } from './utils';

/**
 * Dilution-liquid weights are derived values, so they are rebuilt from the
 * local breakdown instead of the live backend preview. Preview and snapshot
 * cycles nudge the backend water weight by float noise, which would otherwise
 * keep drifting the dirty signature and flag a saved bench as unsaved.
 */
function serializedDilutionLiquidWeight(state, row) {
    const totalLiquidWeight = nonNegativeNumber(buildLyeBreakdown(state)?.water_weight);

    return totalLiquidWeight * (nonNegativeNumber(row.percentage) / 100);
}

export function serializeRow(state, row, phaseKey = null) {
    return {
        id: row.id,
        ingredient_id: row.ingredient_id,
        percentage: nonNegativeNumber(row.percentage),
        weight: phaseKey === 'lye_water'
            ? serializedDilutionLiquidWeight(state, row)
            : rowWeight(state, row),
        note: row.note ?? null,
    };
}

export function serializeDraft(state) {
    const phaseItems = Object.fromEntries(
        Object.entries(state.phaseItems ?? {}).map(([phaseKey, rows]) => [
            phaseKey,
            Array.isArray(rows) ? rows.map((row) => serializeRow(state, row, phaseKey)) : [],
        ]),
    );

    return {
        name: state.formulaName,
        oil_unit: state.oilUnit,
        oil_weight: nonNegativeNumber(state.oilWeight),
        manufacturing_mode: state.manufacturingMode,
        exposure_mode: state.exposureMode,
        regulatory_regime: state.regulatoryRegime,
        product_type_id: state.productTypeId,
        editing_mode: state.editMode,
        lye_type: state.lyeType,
        koh_purity_percentage: nonNegativeNumber(state.kohPurity),
        dual_lye_koh_percentage: nonNegativeNumber(state.dualKohPercentage),
        water_mode: state.waterMode,
        water_value: nonNegativeNumber(state.waterValue),
        superfat: number(state.superfat),
        ifra_category_selection_mode: state.ifraCategorySelectionMode ?? 'automatic',
        ifra_product_category_id: normalizedIfraProductCategoryId(state.selectedIfraProductCategoryId),
        final_ingredient_list: state.finalIngredientList ?? null,
        final_ingredient_list_basis_hash: state.finalIngredientListBasisHash ?? null,
        final_plain_ingredient_list: state.finalPlainIngredientList ?? null,
        final_plain_ingredient_list_basis_hash: state.finalPlainIngredientListBasisHash ?? null,
        phases: (state.phaseOrder ?? []).map((phase) => ({
            key: phase.key,
            name: phase.name,
        })),
        phase_items: phaseItems,
        packaging_items: (state.packagingPlanRows ?? []).map((row, index) => ({
            packaging_item_id: row.packaging_item_id ?? null,
            name: row.name ?? '',
            components_per_unit: nonNegativeNumber(row.components_per_unit),
            notes: row.notes ?? null,
            position: index + 1,
        })),
        production_output_type: state.productionOutputType ?? 'finished_product',
        output_ingredient_id: state.outputIngredientId === '' || state.outputIngredientId === null || state.outputIngredientId === undefined
            ? null
            : Number(state.outputIngredientId),
        ready_delay_days: state.readyDelayDays === '' || state.readyDelayDays === null || state.readyDelayDays === undefined
            ? null
            : Number(state.readyDelayDays),
        product_reference: state.productReference?.trim() || null,
        nominal_content_value: state.nominalContentValue === '' || state.nominalContentValue === null || state.nominalContentValue === undefined
            ? null
            : number(state.nominalContentValue),
        nominal_content_unit: state.nominalContentValue === '' || state.nominalContentValue === null || state.nominalContentValue === undefined
            ? null
            : state.nominalContentUnit || null,
    };
}

/**
 * Serialize the current costing state into the payload expected by the backend's
 * saveCosting endpoint. Includes settings, ingredient prices (one per formula row),
 * and packaging rows with their components-per-unit usage.
 */
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
            price_per_kg: state.canonicalPricePerKg(row),
        })),
        packaging_items: state.packagingCostRows.map((row) => ({
            packaging_item_id: row.packaging_item_id ?? null,
            name: row.name,
            unit_cost: nonNegativeNumber(row.unit_cost),
            components_per_unit: nonNegativeNumber(row.quantity),
        })),
    };
}
