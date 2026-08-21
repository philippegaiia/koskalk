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
import { massDisplayDecimals as chooseMassDisplayDecimals } from '../mass';

const formulaMassDisplayDecimals = typeof chooseMassDisplayDecimals === 'function'
    ? chooseMassDisplayDecimals
    : (() => 2);

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

        get lyeLiquidRows() {
            return this.phaseItems?.lye_water ?? [];
        },

        lyeLiquidPercentageTotal() {
            return this.lyeLiquidRows.reduce(
                (total, row) => total + Math.max(0, parseDecimal(row?.percentage)),
                0,
            );
        },

        lyeLiquidWaterPercentage() {
            return Math.max(0, 100 - this.lyeLiquidPercentageTotal());
        },

        lyeLiquidTotalWeight() {
            return this.number(
                this.backendCalculation?.lye?.water?.weight ?? this.lyeBreakdown().water_weight,
            );
        },

        lyeLiquidWeight(row) {
            return this.lyeLiquidTotalWeight() * (this.nonNegativeNumber(row?.percentage) / 100);
        },

        lyeLiquidWaterWeight() {
            return this.lyeLiquidTotalWeight() * (this.lyeLiquidWaterPercentage() / 100);
        },

        lyeLiquidSelectionSummary() {
            if (this.lyeLiquidRows.length === 0) {
                return this.t('settings.lye_liquid_water_only');
            }

            if (this.lyeLiquidPercentageTotal() >= 100) {
                return this.lyeLiquidRows.length === 1
                    ? this.t('settings.lye_liquid_water_free_singular')
                    : this.t('settings.lye_liquid_water_free_plural', { count: this.lyeLiquidRows.length });
            }

            return this.lyeLiquidRows.length === 1
                ? this.t('settings.lye_liquid_selected_singular')
                : this.t('settings.lye_liquid_selected_plural', { count: this.lyeLiquidRows.length });
        },

        lyeLiquidSummaryCards() {
            return [
                {
                    id: 'water',
                    label: this.t('settings.water'),
                    value: this.lyeLiquidWaterWeight(),
                    kind: 'liquid',
                },
                ...this.lyeLiquidRows.map((row) => ({
                    id: `lye-liquid-${row.id ?? row.ingredient_id}`,
                    label: row.name,
                    value: this.lyeLiquidWeight(row),
                    kind: 'liquid',
                })),
            ];
        },

        get lyeLiquidCompositionIsValid() {
            return this.lyeLiquidPercentageTotal() <= 100.00001;
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
                    label: this.t('cosmetic.category_label'),
                    value: this.productTypeName ?? this.t('common.choose_later'),
                    tone: 'neutral',
                },
                {
                    id: 'formula-weight',
                    label: this.isCosmeticFormula ? this.t('common.total_batch') : 'Base',
                    value: `${this.format(this.oilWeight, this.oilUnit === 'g' ? 0 : 2)} ${this.oilUnit}`,
                    tone: 'neutral',
                },
                {
                    id: 'formula-entry',
                    label: this.isCosmeticFormula ? this.t('cosmetic.entry_label') : 'Entry',
                    value: this.editMode === 'weight' ? this.t('common.weight') : (this.isCosmeticFormula ? this.t('common.formula_percent') : '% oils'),
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
                        label: this.t('settings.lye_liquid'),
                        value: `${this.lyeLiquidSelectionSummary()} · ${this.waterModeSummaryLabel}`,
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

            if (this.canPersist) {
                cards.unshift({
                    id: 'formula-output-type',
                    label: this.t('settings.production_output'),
                    value: this.productionOutputType === 'manufactured_ingredient'
                        ? this.t('settings.manufactured_ingredient')
                        : this.t('settings.finished_product'),
                    tone: 'info',
                });
            }

            cards.push(
                {
                    id: 'formula-exposure',
                    label: this.t('cosmetic.exposure_label'),
                    value: this.exposureModeLabel,
                    tone: 'info',
                },
                {
                    id: 'formula-label',
                    label: this.t('cosmetic.label_label'),
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
                label: this.isCosmeticFormula ? this.t('cosmetic.balance_label') : this.t('status.oils'),
                value: `${this.format(total, 2)}%`,
                detail: this.oilPercentageIsBalanced
                    ? this.t('status.ready')
                    : this.t('status.balanced_remaining', { amount: this.format(delta, 2) }),
                tone: this.oilPercentageIsBalanced ? 'success' : 'danger',
            };
        },

        get lyeWaterDiagnostic() {
            const waterWeight = this.lyeLiquidTotalWeight();
            const lyeWeight = this.totalLyeToWeigh();
            const hasResolvedWeights = this.oilWeightTotal() > 0 && lyeWeight > 0;

            return {
                id: 'lye-water',
                label: this.t('status.lye_water'),
                value: hasResolvedWeights
                    ? `${this.format(lyeWeight, this.calculatedMassDecimals(lyeWeight))} / ${this.format(waterWeight, this.calculatedMassDecimals(waterWeight))} ${this.oilUnit}`
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
                    : this.t(
                        rowCount === 1 ? 'cosmetic.quantity_complete_singular' : 'cosmetic.quantity_complete_plural',
                        { count: rowCount },
                    ),
                tone: zeroRows.length > 0 ? 'warning' : 'success',
            };
        },

        get complianceDiagnostic() {
            return {
                id: 'compliance-context',
                label: this.t('cosmetic.label_context'),
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

                cards.push(...this.lyeLiquidSummaryCards());

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

            cards.push(...this.lyeLiquidSummaryCards());

            return cards;
        },

        totalLyeToWeigh() {
            return calculateTotalLyeToWeigh(this);
        },

        formatLyeSummaryCardValue(card) {
            const value = this.number(card?.value ?? 0);

            return this.format(value, this.calculatedMassDecimals(value));
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
            return this.oilPercentageReadoutTotal === 100;
        },

        get oilPercentageReadoutTotal() {
            return this.number(this.format(this.totalOilPercentage(), 2));
        },

        get oilPercentageStatusLabel() {
            const total = this.oilPercentageReadoutTotal;

            if (this.oilPercentageIsBalanced) {
                return this.t('status.balanced');
            }

            const amount = this.format(Math.abs(100 - total), 2);

            return total < 100
                ? this.t('status.add_percentage', { amount })
                : this.t('status.remove_percentage', { amount });
        },

        get canSaveDraft() {
            if (this.isCosmeticFormula) {
                return this.nonNegativeNumber(this.oilWeight) > 0;
            }

            return this.oilPercentageIsBalanced && this.lyeLiquidCompositionIsValid;
        },

        get canSaveRecipe() {
            return this.oilPercentageIsBalanced && this.lyeLiquidCompositionIsValid;
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

            if (!this.formulaItemLimitReached()) {
                this.formulaItemLimitMessage = '';
            }
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

        decimalAlignmentStyle(value) {
            const numericValue = this.number(value);
            const signCharacters = numericValue < 0 ? 1 : 0;
            const integerCharacters = Math.trunc(Math.abs(numericValue)).toString().length + signCharacters;

            return `--sk-decimal-offset: ${integerCharacters}ch`;
        },

        syncFormattedInput(element, value, decimals) {
            if (document.activeElement === element) {
                return;
            }

            element.value = this.format(value, decimals);
        },

        massDecimals(value, profile = 'standard') {
            return formulaMassDisplayDecimals(value, this.oilUnit, profile);
        },

        oilWeightDecimals(value) {
            return this.massDecimals(value);
        },

        additionWeightDecimals(value) {
            return this.massDecimals(value, 'addition');
        },

        calculatedMassDecimals(value) {
            return this.massDecimals(value, 'calculated');
        },

        format(value, decimals = 2) {
            return formatNumber(value, decimals, this.numberLocale);
        },
    };
}
