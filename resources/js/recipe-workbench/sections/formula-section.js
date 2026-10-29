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
import { formatDecimalInput } from '../number-format';
import { massDisplayDecimals as chooseMassDisplayDecimals } from '../mass';

const formulaMassDisplayDecimals = typeof chooseMassDisplayDecimals === 'function'
    ? chooseMassDisplayDecimals
    : (() => 2);

const oilWeightInputStates = new WeakMap();

function getOilWeightInputState(element) {
    if (!element || (typeof element !== 'object' && typeof element !== 'function')) {
        return null;
    }

    let state = oilWeightInputStates.get(element);

    if (!state) {
        state = {
            edited: false,
            rawValue: null,
        };
        oilWeightInputStates.set(element, state);
    }

    return state;
}

/**
 * Formula math and normalized numeric helpers stay together so the editor-side
 * calculation logic is no longer scattered across unrelated UI helpers.
 */
export function createFormulaSection() {
    return {
        get isCosmeticFormula() {
            return this.productFamilySlug === 'cosmetic';
        },

        get entryModeHelperText() {
            if (this.editMode === 'weight') {
                return this.isCosmeticFormula
                    ? this.t('settings.cosmetic_weight_entry_help')
                    : this.t('settings.soap_weight_entry_help');
            }

            return this.isCosmeticFormula
                ? this.t('settings.cosmetic_percentage_entry_help')
                : this.t('settings.soap_percentage_entry_help');
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

        formatLyeLiquidMass(value) {
            return this.isCalculationRejected
                ? '—'
                : this.format(value, this.calculatedMassDecimals(value));
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
                return this.t('settings.summary.dual', {
                    naoh: this.format(this.dualNaohPercentage, 0),
                    koh: this.format(this.dualKohPercentage, 0),
                });
            }

            return 'NaOH';
        },

        get waterModeSummaryLabel() {
            if (this.waterMode === 'lye_ratio') {
                return this.t('settings.summary.water_ratio', { value: this.format(this.waterValue, 2) });
            }

            if (this.waterMode === 'lye_concentration') {
                return this.t('settings.summary.lye_concentration', { value: this.format(this.waterValue, 1) });
            }

            return this.t('soap.percent_of_oils', { amount: this.format(this.waterValue, 1) });
        },

        get formulaSetupLabelSummary() {
            const labels = [this.isMarketCheckOff ? this.regulatoryRegimeLabel : (this.regulatoryRegime?.toUpperCase() ?? '')];

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
                    context: true,
                },
                {
                    id: 'formula-weight',
                    label: this.isCosmeticFormula ? this.t('common.total_batch') : this.t('settings.summary.base'),
                    value: `${this.format(this.oilWeight, this.oilUnit === 'g' ? 0 : 2)} ${this.oilUnit}`,
                    tone: 'neutral',
                },
                {
                    id: 'formula-entry',
                    label: this.isCosmeticFormula ? this.t('cosmetic.entry_label') : this.t('settings.summary.entry'),
                    value: this.editMode === 'weight' ? this.t('common.weight') : (this.isCosmeticFormula ? this.t('common.formula_percent') : this.t('settings.soap_percentage_entry_label')),
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
                        label: this.t('settings.summary.lye'),
                        value: this.lyeTypeSummaryLabel,
                        tone: 'chemistry',
                    },
                    {
                        id: 'formula-water',
                        label: this.t('settings.lye_liquid'),
                        value: this.lyeLiquidRows.length === 0
                            ? this.waterModeSummaryLabel
                            : `${this.lyeLiquidSelectionSummary()} · ${this.waterModeSummaryLabel}`,
                        tone: 'chemistry',
                    },
                    {
                        id: 'formula-superfat',
                        label: this.t(this.superfat < 0 ? 'settings.summary.lye_excess' : 'settings.summary.lye_reduction'),
                        value: `${this.format(Math.abs(this.superfat), 1)}%`,
                        tone: { excess_lye: 'danger', low_margin: 'warning' }[this.superfatStatus] ?? 'chemistry',
                    },
                );

                if (this.estimatedFreeFat !== null) {
                    cards.push({
                        id: 'formula-free-fat',
                        label: this.t('settings.free_fat'),
                        value: `${this.format(this.estimatedFreeFat, 2)}% ${this.t(this.freeFatSummary.basis === 'finished_liquid_soap' ? 'settings.free_fat_liquid_basis' : 'settings.free_fat_cured_basis')}`,
                        tone: 'chemistry',
                    });
                }
            }

            if (this.canPersist) {
                cards.unshift({
                    id: 'formula-output-type',
                    label: this.t('settings.production_output'),
                    value: this.productionOutputType === 'manufactured_ingredient'
                        ? this.t('settings.manufactured_ingredient')
                        : this.t('settings.finished_product'),
                    tone: 'info',
                    context: true,
                });
            }

            cards.push(
                {
                    id: 'formula-exposure',
                    label: this.t('cosmetic.exposure_label'),
                    value: this.exposureModeLabel,
                    tone: 'info',
                    context: true,
                },
                {
                    id: 'formula-label',
                    label: this.t('cosmetic.label_label'),
                    value: this.formulaSetupLabelSummary,
                    tone: 'info',
                    context: true,
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
                value: `${this.formatPercentageTotal(total)}%`,
                detail: this.oilPercentageIsBalanced
                    ? (this.isGuestCalculator ? this.t('status.amounts_calculated') : this.t('status.ready'))
                    : this.t('status.balanced_remaining', { amount: this.format(delta, 2) }),
                tone: this.oilPercentageIsBalanced ? 'success' : 'danger',
            };
        },

        get lyeWaterDiagnostic() {
            if (this.isCalculationRejected) {
                return {
                    id: 'lye-water',
                    label: this.t('status.lye_water'),
                    value: '—',
                    detail: this.calculationPreviewMessage,
                    tone: 'danger',
                };
            }

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
                value: zeroRows.length > 0 ? this.t('status.zero_count', { count: zeroRows.length }) : this.t('status.none'),
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

            return this.isGuestCalculator ? cards.filter((card) => !['compliance-context', 'formula-state'].includes(card.id)) : cards;
        },

        get formulaDiagnosticSummaryCards() {
            return [
                this.formulaBalanceDiagnostic,
                this.zeroQuantityDiagnostic,
                ...(this.isGuestCalculator ? [] : [this.draftDiagnostic]),
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

        /**
         * The server refused these soap settings, so locally estimated lye and
         * batch masses must not be shown as usable amounts.
         */
        get isCalculationRejected() {
            return !this.isCosmeticFormula
                && this.backendCalculation === null
                && Boolean(this.calculationPreviewMessage);
        },

        get superfatStatus() {
            const status = this.backendCalculation?.lye?.superfat_status ?? null;

            if (status) {
                return status;
            }

            return this.number(this.superfat) < 0 ? 'excess_lye' : 'normal';
        },

        get uncountedPostReactionOils() {
            const names = this.backendCalculation?.lye?.uncounted_post_reaction_oils;

            return Array.isArray(names) ? names : [];
        },

        get freeFatSummary() {
            return this.isCalculationRejected ? null : (this.backendLabeling?.free_fat ?? null);
        },

        get nominalSuperfat() {
            return this.freeFatSummary ? this.number(this.freeFatSummary.nominal_percentage) : null;
        },

        get estimatedFreeFat() {
            return this.freeFatSummary?.percentage != null ? this.number(this.freeFatSummary.percentage) : null;
        },

        get lyeSummaryCards() {
            const backendLye = this.backendCalculation?.lye ?? null;
            const cards = [];

            if (backendLye) {
                if (this.lyeType === 'naoh') {
                    cards.push({
                        id: 'naoh-to-weigh',
                        label: this.t('soap.naoh'),
                        value: backendLye.selected?.naoh_weight ?? 0,
                    });
                } else if (this.lyeType === 'koh') {
                    cards.push({
                        id: 'koh-to-weigh',
                        label: this.t(this.kohPurity === 90 ? 'soap.koh_90' : 'soap.koh'),
                        value: backendLye.selected?.koh_to_weigh ?? 0,
                    });
                } else {
                    cards.push(
                        {
                            id: 'dual-naoh-to-weigh',
                            label: this.t('soap.naoh'),
                            value: backendLye.selected?.naoh_weight ?? 0,
                        },
                        {
                            id: 'dual-koh-to-weigh',
                            label: this.t(this.kohPurity === 90 ? 'soap.koh_90' : 'soap.koh'),
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
                    label: this.t('soap.naoh'),
                    value: lye.selected_naoh_weight,
                });
            } else if (this.lyeType === 'koh') {
                cards.push({
                    id: 'koh-to-weigh',
                    label: this.t(this.kohPurity === 90 ? 'soap.koh_90' : 'soap.koh'),
                    value: lye.koh_to_weigh,
                });
            } else {
                cards.push(
                    {
                        id: 'dual-naoh-to-weigh',
                        label: this.t('soap.naoh'),
                        value: lye.selected_naoh_weight,
                    },
                    {
                        id: 'dual-koh-to-weigh',
                        label: this.t(this.kohPurity === 90 ? 'soap.koh_90' : 'soap.koh'),
                        value: lye.koh_to_weigh,
                    },
                );
            }

            cards.push(...this.lyeLiquidSummaryCards());

            return this.isCalculationRejected
                ? cards.map((card) => ({ ...card, value: null }))
                : cards;
        },

        totalLyeToWeigh() {
            return calculateTotalLyeToWeigh(this);
        },

        formatLyeSummaryCardValue(card) {
            if (card?.value === null) {
                return '—';
            }

            const value = this.number(card?.value ?? 0);

            return `${this.format(value, this.calculatedMassDecimals(value))} ${this.oilUnit}`;
        },

        formatPercentageTotal(value) {
            const readout = this.number(this.format(value, 2));

            return this.format(readout, Number.isInteger(readout) ? 0 : 2);
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
            this.removedFormulaRowUndo = null;
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
            this.removedFormulaRowUndo = null;
        },

        requestCosmeticPhaseRemoval(phaseKey, trigger = null) {
            if (this.phaseOrder.length <= 1) {
                return false;
            }

            const phase = this.phaseOrder.find((candidate) => candidate.key === phaseKey);

            if (!phase) {
                return false;
            }

            this.pendingCosmeticPhaseRemoval = {
                phaseKey,
                phaseName: phase.name,
                rowCount: Array.isArray(this.phaseItems?.[phaseKey])
                    ? this.phaseItems[phaseKey].length
                    : 0,
            };
            this.pendingCosmeticPhaseRemovalTrigger = trigger;

            return true;
        },

        cancelCosmeticPhaseRemoval() {
            const trigger = this.pendingCosmeticPhaseRemovalTrigger;

            this.pendingCosmeticPhaseRemoval = null;
            this.pendingCosmeticPhaseRemovalTrigger = null;

            const focusTrigger = () => {
                trigger?.focus?.();
            };

            if (typeof this.$nextTick === 'function') {
                this.$nextTick(focusTrigger);
            } else if (typeof queueMicrotask === 'function') {
                queueMicrotask(focusTrigger);
            } else {
                setTimeout(focusTrigger, 0);
            }

            return trigger;
        },

        confirmCosmeticPhaseRemoval() {
            const phaseKey = this.pendingCosmeticPhaseRemoval?.phaseKey;

            this.pendingCosmeticPhaseRemoval = null;
            this.pendingCosmeticPhaseRemovalTrigger = null;

            if (!phaseKey) {
                return false;
            }

            return this.removeCosmeticPhase(phaseKey);
        },

        removeCosmeticPhase(phaseKey) {
            if (this.phaseOrder.length <= 1 || !this.phaseOrder.some((phase) => phase.key === phaseKey)) {
                return false;
            }

            this.phaseOrder = this.phaseOrder.filter((phase) => phase.key !== phaseKey);
            const nextPhaseItems = { ...this.phaseItems };
            delete nextPhaseItems[phaseKey];
            this.phaseItems = nextPhaseItems;

            if (!this.formulaItemLimitReached()) {
                this.formulaItemLimitMessage = '';
            }

            this.removedFormulaRowUndo = null;

            return true;
        },

        number(value) {
            return coerceNumber(value);
        },

        parseDecimalInput(value) {
            return parseDecimal(value);
        },

        updateOilWeight(event) {
            const input = event?.target;

            this.oilWeight = this.nonNegativeNumber(input?.value ?? '');

            const inputState = getOilWeightInputState(input);

            if (inputState) {
                inputState.edited = true;
            }
        },

        normalizeOilWeightBlur(event) {
            const input = event?.target;
            const raw = `${input?.value ?? ''}`.trim();
            const inputState = getOilWeightInputState(input);

            if (raw === '') {
                this.oilWeight = 0;

                if (input) {
                    input.value = '';
                }

                if (inputState) {
                    inputState.edited = false;
                    inputState.rawValue = '';
                }

                return;
            }

            const isUntouchedFormattedValue = inputState
                && !inputState.edited
                && inputState.rawValue === raw;

            if (!isUntouchedFormattedValue) {
                this.oilWeight = this.nonNegativeNumber(raw);
            }

            this.syncOilWeightInput(input, true);
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

        syncOilWeightInput(element, force = false) {
            if (!element) {
                return;
            }

            const value = this.nonNegativeNumber(this.oilWeight);
            const formattedValue = formatDecimalInput(
                value,
                this.numberLocale,
                this.oilWeightDecimals(value),
            );

            const isActive = typeof document !== 'undefined' && document.activeElement === element;

            if (!force && isActive) {
                return;
            }

            element.value = formattedValue;

            const inputState = getOilWeightInputState(element);

            if (inputState) {
                inputState.edited = false;
                inputState.rawValue = formattedValue;
            }
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
