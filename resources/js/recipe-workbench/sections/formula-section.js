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
    parseDecimalInput as parseDecimal,
    roundTo as roundNumberTo,
} from '../utils';

/**
 * Formula math and normalized numeric helpers stay together so the editor-side
 * calculation logic is no longer scattered across unrelated UI helpers.
 */
export function createFormulaSection() {
    return {
        get isCosmeticFormula() {
            return this.productFamilySlug === 'cosmetic';
        },

        get oilRows() {
            return this.phaseItems.saponified_oils ?? [];
        },

        get additiveRows() {
            return this.phaseItems.additives ?? [];
        },

        get fragranceRows() {
            return this.phaseItems.fragrance ?? [];
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
            row.percentage = calculatePercentageFromWeight(this.oilWeight, parseDecimal(weightValue));
        },

        updateOilPercentagesFromWeights(row, weightValue) {
            const updatedWeights = buildOilPercentagesFromWeights(this.oilRows, this.oilWeight, row.id, parseDecimal(weightValue));

            this.oilWeight = updatedWeights.oilWeight;
            this.oilRows.forEach((oilRow) => {
                oilRow.percentage = updatedWeights.percentagesByRowId.get(oilRow.id) ?? 0;
            });
        },

        totalOilPercentage() {
            if (this.isCosmeticFormula) {
                return this.cosmeticFormulaPercentageTotal();
            }

            return getOilPercentageTotal(this.oilRows);
        },

        get oilPercentageIsBalanced() {
            return Math.abs(this.totalOilPercentage() - 100) <= 0.01;
        },

        get oilPercentageStatusLabel() {
            if (this.isCosmeticFormula) {
                return this.oilPercentageIsBalanced ? 'Formula balanced' : 'Formula must reach 100%';
            }

            return this.oilPercentageIsBalanced ? 'Oil basis balanced' : 'Oil basis must reach 100%';
        },

        get canSaveDraft() {
            if (this.isCosmeticFormula) {
                return this.nonNegativeNumber(this.oilWeight) > 0;
            }

            return this.oilPercentageIsBalanced;
        },

        get canSaveRecipe() {
            return this.oilPercentageIsBalanced;
        },

        get canDuplicateFormula() {
            return this.canSaveDraft;
        },

        totalAdditionPercentage() {
            return calculateTotalAdditionPercentage(this.additiveRows, this.fragranceRows);
        },

        sumPercentages(rows) {
            return calculateSumPercentages(rows);
        },

        oilWeightTotal() {
            if (this.isCosmeticFormula) {
                return this.cosmeticFormulaWeightTotal();
            }

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

        cosmeticDefaultPhaseKey() {
            return this.phaseOrder[0]?.key ?? 'phase_a';
        },

        cosmeticFormulaRows() {
            return this.phaseOrder.flatMap((phase) => this.phaseItems[phase.key] ?? []);
        },

        cosmeticFormulaPercentageTotal() {
            return this.cosmeticFormulaRows()
                .reduce((total, row) => total + this.nonNegativeNumber(row.percentage), 0);
        },

        cosmeticFormulaWeightTotal() {
            return this.cosmeticFormulaRows()
                .reduce((total, row) => total + this.rowWeight(row), 0);
        },

        cosmeticPhasePercentageTotal(phaseKey) {
            return (this.phaseItems[phaseKey] ?? [])
                .reduce((total, row) => total + this.nonNegativeNumber(row.percentage), 0);
        },

        cosmeticPhaseWeightTotal(phaseKey) {
            return (this.phaseItems[phaseKey] ?? [])
                .reduce((total, row) => total + this.rowWeight(row), 0);
        },

        addCosmeticPhase() {
            const nextIndex = this.phaseOrder.length;
            let candidate = `phase_${String.fromCharCode(97 + nextIndex)}`;
            let suffix = nextIndex + 1;

            while (Object.hasOwn(this.phaseItems, candidate)) {
                candidate = `phase_${suffix}`;
                suffix += 1;
            }

            this.phaseOrder = [
                ...this.phaseOrder,
                {
                    key: candidate,
                    name: `Phase ${String.fromCharCode(65 + nextIndex)}`,
                },
            ];
            this.phaseItems = {
                ...this.phaseItems,
                [candidate]: [],
            };
        },

        cosmeticPhaseIndex(phaseKey) {
            return this.phaseOrder.findIndex((phase) => phase.key === phaseKey);
        },

        cosmeticPhaseIsFirst(phaseKey) {
            return this.cosmeticPhaseIndex(phaseKey) <= 0;
        },

        cosmeticPhaseIsLast(phaseKey) {
            const index = this.cosmeticPhaseIndex(phaseKey);

            return index === -1 || index >= this.phaseOrder.length - 1;
        },

        moveCosmeticPhase(phaseKey, direction) {
            const currentIndex = this.cosmeticPhaseIndex(phaseKey);
            const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;

            if (
                currentIndex < 0
                || targetIndex < 0
                || targetIndex >= this.phaseOrder.length
            ) {
                return;
            }

            const nextPhaseOrder = [...this.phaseOrder];
            const [phase] = nextPhaseOrder.splice(currentIndex, 1);
            nextPhaseOrder.splice(targetIndex, 0, phase);
            this.phaseOrder = nextPhaseOrder;
        },

        confirmRemoveCosmeticPhase(phaseKey) {
            const phaseRows = this.phaseItems[phaseKey] ?? [];
            const message = phaseRows.length > 0
                ? 'Remove this phase and its ingredients?'
                : 'Remove this phase?';

            if (!window.confirm(message)) {
                return;
            }

            this.removeCosmeticPhase(phaseKey);
        },

        removeCosmeticPhase(phaseKey) {
            if (this.phaseOrder.length <= 1) {
                return;
            }

            this.phaseOrder = this.phaseOrder.filter((phase) => phase.key !== phaseKey);
            const nextPhaseItems = { ...this.phaseItems };
            delete nextPhaseItems[phaseKey];
            this.phaseItems = nextPhaseItems;
        },

        number(value) {
            return coerceNumber(value);
        },

        handleDecimalKeydown(event) {
            if (event.key === ',') {
                event.preventDefault();
                const el = event.target;
                const start = el.selectionStart;
                const end = el.selectionEnd;

                if (start === null || end === null || typeof el.setRangeText !== 'function') {
                    el.value = `${el.value ?? ''}.`;
                    el.dispatchEvent(new Event('input', { bubbles: true }));

                    return;
                }

                el.setRangeText('.', start, end, 'end');
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },

        parseDecimalInput(value) {
            return parseDecimal(value);
        },

        normalizeSapValue(value) {
            return getNormalizedSapValue(value);
        },

        confirmNegativeSuperfat(event) {
            const value = parseFloat(event.target.value);

            if (isNaN(value) || value >= 0 || this.superfat < 0) {
                return;
            }

            if (!window.confirm('Negative superfat means excess lye in the finished product. This can cause skin irritation or burns. Proceed with a negative superfat?')) {
                this.superfat = 0;
            }
        },

        format(value, decimals = 2) {
            return formatNumber(value, decimals);
        },
    };
}
