import {
    averageFattyAcidProfile as buildAverageFattyAcidProfile,
    curedBatchWeight as calculateCuredBatchWeight,
    finalBatchWeight as calculateFinalBatchWeight,
    lyeBreakdown as buildLyeBreakdown,
    oilPercentageTotal as getOilPercentageTotal,
    rowWeight as calculateRowWeight,
    sumPercentages as calculateSumPercentages,
    totalAdditionPercentage as calculateTotalAdditionPercentage,
    totalFormulaPercentage as calculateTotalFormulaPercentage,
    updateFormulaPercentagesFromWeights as buildFormulaPercentagesFromWeights,
    totalLyeToWeigh as calculateTotalLyeToWeigh,
    updateOilPercentagesFromWeights as buildOilPercentagesFromWeights,
    updatePercentageFromWeight as calculatePercentageFromWeight,
} from '../calculation';
import {
    clampPercentage as clampPercentageValue,
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

        formulaIngredientRows() {
            if (this.isCosmeticFormula) {
                return this.cosmeticFormulaRows();
            }

            return [
                ...this.oilRows,
                ...this.additiveRows,
                ...this.fragranceRows,
            ];
        },

        zeroQuantityRows() {
            return this.formulaIngredientRows()
                .filter((row) => this.nonNegativeNumber(row.percentage) <= 0);
        },

        get lyeTypeSummaryLabel() {
            if (this.lyeType === 'koh') {
                return this.kohPurity === 90 ? 'KOH 90%' : 'KOH';
            }

            if (this.lyeType === 'dual') {
                return `Dual ${this.format(this.dualNaohPercentage, 0)} / ${this.format(this.dualKohPercentage, 0)}`;
            }

            return 'NaOH';
        },

        get waterModeSummaryLabel() {
            if (this.waterMode === 'lye_ratio') {
                return `Ratio ${this.format(this.waterValue, 2)}:1`;
            }

            if (this.waterMode === 'lye_concentration') {
                return `Lye ${this.format(this.waterValue, 1)}%`;
            }

            return `${this.format(this.waterValue, 1)}% oils`;
        },

        get formulaSetupLabelSummary() {
            const labels = [this.regulatoryRegime?.toUpperCase() ?? ''];

            if (this.selectedIfraProductCategory) {
                labels.push(`IFRA ${this.selectedIfraProductCategory.code}`);
            }

            return labels.filter(Boolean).join(' · ');
        },

        get formulaSetupSummaryCards() {
            const cards = [
                {
                    id: 'formula-product-category',
                    label: 'Category',
                    value: this.productTypeName ?? 'Choose later',
                    tone: 'neutral',
                },
                {
                    id: 'formula-weight',
                    label: this.isCosmeticFormula ? 'Total batch quantity' : 'Base',
                    value: `${this.format(this.oilWeight, this.oilUnit === 'g' ? 0 : 2)} ${this.oilUnit}`,
                    tone: 'neutral',
                },
                {
                    id: 'formula-entry',
                    label: 'Entry',
                    value: this.editMode === 'weight' ? 'Weight' : (this.isCosmeticFormula ? '% formula' : '% oils'),
                    tone: 'neutral',
                },
            ];

            if (!this.isCosmeticFormula) {
                cards.shift();
                cards.splice(
                    1,
                    0,
                    {
                        id: 'formula-lye',
                        label: 'Lye',
                        value: this.lyeTypeSummaryLabel,
                        tone: 'chemistry',
                    },
                    {
                        id: 'formula-water',
                        label: 'Water',
                        value: this.waterModeSummaryLabel,
                        tone: 'chemistry',
                    },
                    {
                        id: 'formula-superfat',
                        label: 'Superfat',
                        value: `${this.format(this.superfat, 1)}%`,
                        tone: this.superfat < 0 ? 'danger' : 'chemistry',
                    },
                );
            }

            cards.push(
                {
                    id: 'formula-exposure',
                    label: 'Exposure',
                    value: this.exposureModeLabel,
                    tone: 'info',
                },
                {
                    id: 'formula-label',
                    label: 'Label',
                    value: this.formulaSetupLabelSummary,
                    tone: 'info',
                },
            );

            return cards;
        },

        get formulaBalanceDiagnostic() {
            const total = this.totalOilPercentage();
            const delta = Math.abs(100 - total);

            return {
                id: 'formula-balance',
                label: this.isCosmeticFormula ? 'Formula balance' : this.t('status.oils'),
                value: `${this.format(total, 2)}%`,
                detail: this.oilPercentageIsBalanced
                    ? this.t('status.ready')
                    : this.t('status.balanced_remaining', { amount: this.format(delta, 2) }),
                tone: this.oilPercentageIsBalanced ? 'success' : 'danger',
            };
        },

        get lyeWaterDiagnostic() {
            const waterCard = this.lyeSummaryCards.find((card) => card.id === 'water');
            const waterWeight = this.number(waterCard?.value ?? this.lyeBreakdown().water_weight);
            const lyeWeight = this.totalLyeToWeigh();
            const hasResolvedWeights = this.oilWeightTotal() > 0 && lyeWeight > 0;

            return {
                id: 'lye-water',
                label: this.t('status.lye_water'),
                value: hasResolvedWeights
                    ? `${this.format(lyeWeight, 1)} / ${this.format(waterWeight, 1)} ${this.oilUnit}`
                    : this.t('status.pending'),
                detail: hasResolvedWeights && this.oilPercentageIsBalanced
                    ? this.t('status.amounts_calculated')
                    : this.t('status.waiting'),
                tone: hasResolvedWeights ? 'chemistry' : 'warning',
            };
        },

        get zeroQuantityDiagnostic() {
            const zeroRows = this.zeroQuantityRows();
            const rowCount = this.formulaIngredientRows().length;

            return {
                id: 'zero-quantity',
                label: this.t('status.missing_quantities'),
                value: zeroRows.length > 0 ? `${zeroRows.length} at 0` : this.t('status.none'),
                detail: zeroRows.length > 0
                    ? this.t('status.zero_detail')
                    : `${rowCount} ${rowCount === 1 ? 'ingredient has' : 'ingredients have'} a quantity.`,
                tone: zeroRows.length > 0 ? 'warning' : 'success',
            };
        },

        get complianceDiagnostic() {
            return {
                id: 'compliance-context',
                label: 'Label context',
                value: this.regulatoryRegimeLabel,
                detail: this.regulatoryRegimeCoverageLabel,
                tone: 'info',
            };
        },

        get draftDiagnostic() {
            const hasUnsavedChanges = this.hasUnsavedWorkbenchChanges();
            const hasSaveError = this.saveStatus === 'error';

            return {
                id: 'formula-state',
                label: this.t('status.changes'),
                value: this.isSaving ? this.t('status.saving') : (hasSaveError ? this.t('status.save_failed') : (hasUnsavedChanges ? this.t('status.unsaved') : this.t('status.saved'))),
                detail: this.saveMessage || (this.isSaving ? this.t('status.saving_detail') : (hasUnsavedChanges ? this.t('status.save_changes') : this.t('status.saved_detail'))),
                tone: hasSaveError ? 'danger' : (this.isSaving ? 'neutral' : (hasUnsavedChanges ? 'warning' : 'success')),
            };
        },

        get formulaDiagnosticCards() {
            const cards = [
                this.formulaBalanceDiagnostic,
                this.zeroQuantityDiagnostic,
                this.complianceDiagnostic,
                this.draftDiagnostic,
            ];

            if (!this.isCosmeticFormula) {
                cards.splice(1, 0, this.lyeWaterDiagnostic);
            }

            return cards;
        },

        get formulaDiagnosticSummaryCards() {
            return [
                this.formulaBalanceDiagnostic,
                this.zeroQuantityDiagnostic,
                this.draftDiagnostic,
            ];
        },

        pulseDiagnosticValue(element, signature) {
            if (!element || !signature) {
                return;
            }

            if (element.dataset.diagnosticSignature === undefined) {
                element.dataset.diagnosticSignature = signature;

                return;
            }

            if (element.dataset.diagnosticSignature === signature) {
                return;
            }

            element.dataset.diagnosticSignature = signature;

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || typeof element.animate !== 'function') {
                return;
            }

            element.animate([
                { transform: 'translateY(0)', opacity: 0.82 },
                { transform: 'translateY(-2px)', opacity: 1 },
                { transform: 'translateY(0)', opacity: 1 },
            ], {
                duration: 220,
                easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
            });
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

        clampPercentage(value) {
            return clampPercentageValue(value);
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

        updateCosmeticPercentagesFromWeights(row, weightValue) {
            const updatedWeights = buildFormulaPercentagesFromWeights(this.cosmeticFormulaRows(), this.oilWeight, row.id, parseDecimal(weightValue));

            this.oilWeight = updatedWeights.totalWeight;
            this.cosmeticFormulaRows().forEach((cosmeticRow) => {
                cosmeticRow.percentage = updatedWeights.percentagesByRowId.get(cosmeticRow.id) ?? 0;
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

            return this.oilPercentageIsBalanced ? this.t('saponification.balanced') : this.t('saponification.unbalanced');
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

        parseDecimalInput(value) {
            return parseDecimal(value);
        },

        confirmNegativeSuperfat(event) {
            const value = this.parseDecimalInput(event.target.value);

            if (value >= 0 || this.number(this.superfat) < 0) {
                return;
            }

            if (!window.confirm(this.t('status.negative_superfat_warning'))) {
                this.superfat = 0;
            }
        },

        format(value, decimals = 2) {
            return formatNumber(value, decimals, this.numberLocale);
        },
    };
}
