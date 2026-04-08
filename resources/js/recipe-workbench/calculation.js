import { nonNegativeNumber, number, roundTo } from './utils';

export function rowWeightForOilWeight(oilWeight, row) {
    return nonNegativeNumber(oilWeight) * (nonNegativeNumber(row.percentage) / 100);
}

export function rowWeight(state, row) {
    return rowWeightForOilWeight(state.oilWeight, row);
}

export function updatePercentageFromWeight(oilWeight, weightValue) {
    const totalOilWeight = nonNegativeNumber(oilWeight);

    if (totalOilWeight <= 0) {
        return 0;
    }

    return roundTo((nonNegativeNumber(weightValue) / totalOilWeight) * 100);
}

export function updateOilPercentagesFromWeights(oilRows, oilWeight, rowId, weightValue) {
    const weightsByRowId = new Map(
        oilRows.map((oilRow) => [oilRow.id, rowWeightForOilWeight(oilWeight, oilRow)]),
    );

    weightsByRowId.set(rowId, nonNegativeNumber(weightValue));

    const totalOilWeight = Array.from(weightsByRowId.values())
        .reduce((total, currentWeight) => total + nonNegativeNumber(currentWeight), 0);

    const roundedTotalOilWeight = roundTo(totalOilWeight);
    const percentagesByRowId = new Map;

    if (roundedTotalOilWeight <= 0) {
        oilRows.forEach((oilRow) => {
            percentagesByRowId.set(oilRow.id, 0);
        });

        return {
            oilWeight: roundedTotalOilWeight,
            percentagesByRowId,
        };
    }

    oilRows.forEach((oilRow) => {
        const currentRowWeight = weightsByRowId.get(oilRow.id) ?? 0;

        percentagesByRowId.set(
            oilRow.id,
            roundTo((nonNegativeNumber(currentRowWeight) / roundedTotalOilWeight) * 100),
        );
    });

    return {
        oilWeight: roundedTotalOilWeight,
        percentagesByRowId,
    };
}

export function sumPercentages(rows) {
    return rows.reduce((total, row) => total + nonNegativeNumber(row.percentage), 0);
}

export function oilPercentageTotal(oilRows) {
    return sumPercentages(oilRows);
}

export function normalizeSapValue(value) {
    const sapValue = number(value);

    return sapValue > 1 ? sapValue / 1000 : sapValue;
}

export function oilsMissingSap(oilRows) {
    return oilRows.filter((row) => normalizeSapValue(row.koh_sap_value) <= 0);
}

export function oilWeightTotal(oilRows, oilWeight) {
    return oilRows.reduce((total, row) => total + rowWeightForOilWeight(oilWeight, row), 0);
}

export function additionWeightTotal(state) {
    return [...state.additiveRows, ...state.fragranceRows]
        .reduce((total, row) => total + rowWeight(state, row), 0);
}

export function waterWeightFor(state, selectedLyeWeight) {
    const totalOilWeight = oilWeightTotal(state.oilRows, state.oilWeight);
    const waterValue = number(state.waterValue);

    if (state.waterMode === 'lye_ratio') {
        return selectedLyeWeight * waterValue;
    }

    if (state.waterMode === 'lye_concentration') {
        if (waterValue <= 0 || waterValue >= 100) {
            return 0;
        }

        const concentration = waterValue / 100;

        return (selectedLyeWeight / concentration) - selectedLyeWeight;
    }

    return totalOilWeight * (waterValue / 100);
}

export function lyeBreakdown(state) {
    if (oilsMissingSap(state.oilRows).length > 0) {
        return {
            naoh_theoretical: 0,
            naoh_adjusted: 0,
            koh_theoretical: 0,
            koh_adjusted: 0,
            selected_naoh_weight: 0,
            selected_koh_weight: 0,
            water_weight: waterWeightFor(state, 0),
            glycerine_weight: 0,
            koh_to_weigh: 0,
            selected_total_active_lye: 0,
            fatty_acids: {},
        };
    }

    const totals = state.oilRows.reduce((carry, row) => {
        const currentRowWeight = rowWeight(state, row);
        const kohSap = normalizeSapValue(row.koh_sap_value);
        const naohSap = kohSap * 0.713;

        carry.naoh_theoretical += currentRowWeight * naohSap;
        carry.koh_theoretical += currentRowWeight * kohSap;

        Object.entries(row.fatty_acid_profile ?? {}).forEach(([key, value]) => {
            carry.fatty_acids[key] = (carry.fatty_acids[key] ?? 0) + (currentRowWeight * number(value));
        });

        return carry;
    }, {
        naoh_theoretical: 0,
        koh_theoretical: 0,
        fatty_acids: {},
    });

    const superfatMultiplier = 1 - (number(state.superfat) / 100);
    const naohAdjusted = totals.naoh_theoretical * superfatMultiplier;
    const kohAdjusted = totals.koh_theoretical * superfatMultiplier;
    const kohRatio = state.lyeType === 'dual'
        ? number(state.dualKohPercentage) / 100
        : (state.lyeType === 'koh' ? 1 : 0);
    const naohRatio = 1 - kohRatio;
    const selectedNaohWeight = naohAdjusted * naohRatio;
    const selectedKohWeight = kohAdjusted * kohRatio;
    const selectedTotalActiveLye = selectedNaohWeight + selectedKohWeight;
    const currentWaterWeight = waterWeightFor(state, selectedTotalActiveLye);
    const kohToWeigh = selectedKohWeight > 0 && state.kohPurity === 90 ? selectedKohWeight / 0.9 : selectedKohWeight;

    return {
        naoh_theoretical: totals.naoh_theoretical,
        naoh_adjusted: naohAdjusted,
        koh_theoretical: totals.koh_theoretical,
        koh_adjusted: kohAdjusted,
        selected_naoh_weight: selectedNaohWeight,
        selected_koh_weight: selectedKohWeight,
        koh_to_weigh: kohToWeigh,
        selected_total_active_lye: selectedTotalActiveLye,
        water_weight: currentWaterWeight,
        glycerine_weight: (selectedNaohWeight * (92.09382 / 119.9922)) + (selectedKohWeight * (92.09382 / 168.3168)),
        fatty_acids: totals.fatty_acids,
    };
}

export function averageFattyAcidProfile(state) {
    const totals = lyeBreakdown(state).fatty_acids;
    const totalOilWeight = oilWeightTotal(state.oilRows, state.oilWeight);

    if (totalOilWeight <= 0) {
        return {};
    }

    return Object.keys(totals).sort().reduce((profile, key) => {
        profile[key] = totals[key] / totalOilWeight;

        return profile;
    }, {});
}

export function totalAdditionPercentage(additiveRows, fragranceRows) {
    return sumPercentages([...additiveRows, ...fragranceRows]);
}

export function totalLyeToWeigh(state) {
    const backendLye = state.backendCalculation?.lye ?? null;

    if (backendLye) {
        return number(backendLye.selected?.naoh_weight ?? 0) + number(backendLye.selected?.koh_to_weigh ?? 0);
    }

    const lye = lyeBreakdown(state);

    return number(lye.selected_naoh_weight) + number(lye.koh_to_weigh);
}

export function finalBatchWeight(state) {
    const lye = lyeBreakdown(state);
    const lyeToWeigh = state.lyeType === 'naoh'
        ? lye.selected_naoh_weight
        : (state.lyeType === 'koh' ? lye.koh_to_weigh : lye.selected_naoh_weight + lye.koh_to_weigh);

    return oilWeightTotal(state.oilRows, state.oilWeight) + additionWeightTotal(state) + lye.water_weight + lyeToWeigh;
}

export function curedBatchWeight(state) {
    const wetWeight = finalBatchWeight(state);
    const waterWeight = state.backendCalculation?.lye?.water?.weight ?? lyeBreakdown(state).water_weight;
    const nonWaterWeight = Math.max(0, wetWeight - waterWeight);
    const curedWaterFraction = 0.11;

    return nonWaterWeight / (1 - curedWaterFraction);
}

export function totalFormulaPercentage(state, row) {
    const totalWeight = finalBatchWeight(state);

    if (totalWeight <= 0) {
        return 0;
    }

    return (rowWeight(state, row) / totalWeight) * 100;
}
