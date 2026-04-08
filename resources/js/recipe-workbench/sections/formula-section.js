import {
    averageFattyAcidProfile as buildAverageFattyAcidProfile,
    curedBatchWeight as calculateCuredBatchWeight,
    finalBatchWeight as calculateFinalBatchWeight,
    lyeBreakdown as buildLyeBreakdown,
    normalizeSapValue as getNormalizedSapValue,
    oilPercentageTotal as getOilPercentageTotal,
    rowWeight as calculateRowWeight,
    sumPercentages as calculateSumPercentages,
    totalAdditionPercentage as calculateTotalAdditionPercentage,
    totalFormulaPercentage as calculateTotalFormulaPercentage,
    totalLyeToWeigh as calculateTotalLyeToWeigh,
    updateOilPercentagesFromWeights as buildOilPercentagesFromWeights,
    updatePercentageFromWeight as calculatePercentageFromWeight,
} from '../calculation';
import {
    format as formatNumber,
    nonNegativeNumber as ensureNonNegativeNumber,
    number as coerceNumber,
    roundTo as roundNumberTo,
} from '../utils';

/**
 * Formula math and normalized numeric helpers stay together so the editor-side
 * calculation logic is no longer scattered across unrelated UI helpers.
 */
export function createFormulaSection() {
    return {
        get oilRows() {
            return this.phaseItems.saponified_oils;
        },

        get additiveRows() {
            return this.phaseItems.additives;
        },

        get fragranceRows() {
            return this.phaseItems.fragrance;
        },

        get oilsMissingSap() {
            return this.oilRows.filter((row) => this.normalizeSapValue(row.koh_sap_value) <= 0);
        },

        get dualNaohPercentage() {
            return 100 - this.number(this.dualKohPercentage);
        },

        get lyeSummaryCards() {
            const backendLye = this.backendCalculation?.lye ?? null;
            const cards = [];

            if (backendLye) {
                if (this.lyeType === 'naoh') {
                    cards.push({
                        id: 'naoh-to-weigh',
                        label: 'Lye (NaOH)',
                        value: backendLye.selected?.naoh_weight ?? 0,
                    });
                } else if (this.lyeType === 'koh') {
                    cards.push({
                        id: 'koh-to-weigh',
                        label: this.kohPurity === 90 ? 'Potash (KOH 90%)' : 'Potash (KOH)',
                        value: backendLye.selected?.koh_to_weigh ?? 0,
                    });
                } else {
                    cards.push(
                        {
                            id: 'dual-naoh-to-weigh',
                            label: 'Lye (NaOH)',
                            value: backendLye.selected?.naoh_weight ?? 0,
                        },
                        {
                            id: 'dual-koh-to-weigh',
                            label: this.kohPurity === 90 ? 'Potash (KOH 90%)' : 'Potash (KOH)',
                            value: backendLye.selected?.koh_to_weigh ?? 0,
                        },
                    );
                }

                cards.push({
                    id: 'water',
                    label: 'Water',
                    value: backendLye.water?.weight ?? 0,
                });

                return cards;
            }

            const lye = this.lyeBreakdown();

            if (this.lyeType === 'naoh') {
                cards.push({
                    id: 'naoh-to-weigh',
                    label: 'Lye (NaOH)',
                    value: lye.selected_naoh_weight,
                });
            } else if (this.lyeType === 'koh') {
                cards.push({
                    id: 'koh-to-weigh',
                    label: this.kohPurity === 90 ? 'Potash (KOH 90%)' : 'Potash (KOH)',
                    value: lye.koh_to_weigh,
                });
            } else {
                cards.push(
                    {
                        id: 'dual-naoh-to-weigh',
                        label: 'Lye (NaOH)',
                        value: lye.selected_naoh_weight,
                    },
                    {
                        id: 'dual-koh-to-weigh',
                        label: this.kohPurity === 90 ? 'Potash (KOH 90%)' : 'Potash (KOH)',
                        value: lye.koh_to_weigh,
                    },
                );
            }

            cards.push({
                id: 'water',
                label: 'Water',
                value: lye.water_weight,
            });

            return cards;
        },

        totalLyeToWeigh() {
            return calculateTotalLyeToWeigh(this);
        },

        formatLyeSummaryCardValue(card) {
            const value = this.number(card?.value ?? 0);
            const shouldFloorLye = this.oilUnit === 'g' && card?.id !== 'water' && this.totalLyeToWeigh() > 300;
            const shouldFloorWater = this.oilUnit === 'g' && card?.id === 'water' && value > 300;

            if (shouldFloorLye || shouldFloorWater) {
                return this.format(Math.floor(value), 0);
            }

            return this.format(value, 2);
        },

        nonNegativeNumber(value) {
            return ensureNonNegativeNumber(value);
        },

        roundTo(value, decimals = 3) {
            return roundNumberTo(value, decimals);
        },

        rowWeight(row) {
            return calculateRowWeight(this, row);
        },

        updatePercentageFromWeight(row, weightValue) {
            row.percentage = calculatePercentageFromWeight(this.oilWeight, weightValue);
        },

        updateOilPercentagesFromWeights(row, weightValue) {
            const updatedWeights = buildOilPercentagesFromWeights(this.oilRows, this.oilWeight, row.id, weightValue);

            this.oilWeight = updatedWeights.oilWeight;
            this.oilRows.forEach((oilRow) => {
                oilRow.percentage = updatedWeights.percentagesByRowId.get(oilRow.id) ?? 0;
            });
        },

        totalOilPercentage() {
            return getOilPercentageTotal(this.oilRows);
        },

        get oilPercentageIsBalanced() {
            return Math.abs(this.totalOilPercentage() - 100) <= 0.01;
        },

        get oilPercentageStatusLabel() {
            return this.oilPercentageIsBalanced ? 'Oil basis balanced' : 'Oil basis must reach 100%';
        },

        totalAdditionPercentage() {
            return calculateTotalAdditionPercentage(this.additiveRows, this.fragranceRows);
        },

        sumPercentages(rows) {
            return calculateSumPercentages(rows);
        },

        oilWeightTotal() {
            return this.oilRows.reduce((total, row) => total + this.rowWeight(row), 0);
        },

        additionWeightTotal() {
            return [...this.additiveRows, ...this.fragranceRows]
                .reduce((total, row) => total + this.rowWeight(row), 0);
        },

        lyeBreakdown() {
            return buildLyeBreakdown(this);
        },

        waterWeightFor(selectedLyeWeight) {
            const oilWeight = this.oilWeightTotal();
            const waterValue = this.number(this.waterValue);

            if (this.waterMode === 'lye_ratio') {
                return selectedLyeWeight * waterValue;
            }

            if (this.waterMode === 'lye_concentration') {
                if (waterValue <= 0 || waterValue >= 100) {
                    return 0;
                }

                const concentration = waterValue / 100;

                return (selectedLyeWeight / concentration) - selectedLyeWeight;
            }

            return oilWeight * (waterValue / 100);
        },

        averageFattyAcidProfile() {
            return buildAverageFattyAcidProfile(this);
        },

        finalBatchWeight() {
            return calculateFinalBatchWeight(this);
        },

        curedBatchWeight() {
            return calculateCuredBatchWeight(this);
        },

        totalFormulaPercentage(row) {
            return calculateTotalFormulaPercentage(this, row);
        },

        number(value) {
            return coerceNumber(value);
        },

        normalizeSapValue(value) {
            return getNormalizedSapValue(value);
        },

        format(value, decimals = 2) {
            return formatNumber(value, decimals);
        },
    };
}
