/**
 * Presentation-heavy quality and labeling helpers live here. They still read
 * from the shared workbench state, but they no longer obscure save/load logic.
 */
export function createPresentationSection() {
    return {
        qualityBarStyle(value, color = 'var(--color-line-strong)') {
            const width = Math.max(0, Math.min(100, this.number(value)));

            return `width: ${width}%; background: linear-gradient(90deg, color-mix(in srgb, ${color} 72%, white 28%) 0%, ${color} 100%);`;
        },

        qualityApplicability(key) {
            return this.backendCalculation?.properties?.quality_applicability?.[key] ?? { applies: true, confidence: 1, display: 'score' };
        },

        isQualityApplicable(key) {
            return this.qualityApplicability(key).applies !== false;
        },

        isQualityTendency(key) {
            return this.qualityApplicability(key).display === 'tendency';
        },

        isQualityScored(key) {
            return this.isQualityApplicable(key) && !this.isQualityTendency(key);
        },

        qualityDisplayValue(row) {
            if (!this.isQualityApplicable(row.key)) {
                return 'N/A';
            }

            if (this.isQualityTendency(row.key)) {
                return this.t('qualities.tendency_value', { value: this.format(row.value, 1) });
            }

            return this.format(row.value, 1);
        },

        qualityTone(key, value) {
            if (!this.isQualityApplicable(key)) {
                return 'ideal';
            }

            if (this.isQualityTendency(key)) {
                return 'neutral';
            }

            const numeric = this.number(value);
            const zone = this.qualityTargetZone(key);

            if (!zone) {
                return 'ideal';
            }

            if (['dos_risk', 'slime_risk', 'shrinkage_risk'].includes(key)) {
                if (numeric <= 20) return 'ideal';
                if (numeric < 35) return 'low';
                if (numeric < 60) return 'high';

                return 'excess';
            }

            if (key === 'cleansing_strength') {
                if (numeric < zone.start) return numeric < 10 ? 'very-low' : 'low';
                if (numeric <= zone.end) return 'ideal';
                if (numeric < 65) return 'high';

                return 'excess';
            }

            if (numeric < zone.start) {
                return numeric < 20 ? 'very-low' : 'low';
            }

            if (numeric <= zone.end) return 'ideal';

            return numeric < 85 ? 'high' : 'excess';
        },

        qualityToneColor(key, value) {
            const colors = {
                'very-low': 'var(--color-quality-very-low)',
                low: 'var(--color-quality-low)',
                ideal: 'var(--color-quality-ideal)',
                neutral: 'var(--color-line-strong)',
                high: 'var(--color-quality-high)',
                excess: 'var(--color-quality-excess)',
            };

            return colors[this.qualityTone(key, value)] ?? colors.ideal;
        },

        qualityLevelStyle(key, value) {
            return `--quality-tone: ${this.qualityToneColor(key, value)};`;
        },

        qualityCardStyle(key, value) {
            if (!this.isQualityApplicable(key)) {
                return 'border-[var(--color-line)] bg-[var(--color-field)] opacity-80';
            }

            if (this.isQualityTendency(key)) {
                return 'border-[var(--color-line)] bg-[var(--color-field)]';
            }

            const tone = this.qualityTone(key, value);

            if (tone === 'ideal') {
                return 'border-[var(--color-line)] bg-white';
            }

            if (tone === 'excess') {
                return 'border-[var(--color-danger-soft)] bg-white';
            }

            return 'border-[var(--color-line)] bg-white';
        },

        qualityTargetLabel(key) {
            if (!this.isQualityApplicable(key)) {
                return this.t('qualities.not_applicable');
            }

            if (this.isQualityTendency(key)) {
                return this.t('qualities.process_dependent');
            }

            const range = this.qualityTargetRangeLabel(key);

            return range ? this.t('qualities.target', { range }) : null;
        },

        qualityTargetRangeLabel(key) {
            const zone = this.qualityTargetZone(key);

            if (!zone) {
                return null;
            }

            return `${zone.start}-${zone.end}`;
        },

        fattyAcidRowBarStyle(value, color = 'var(--color-ink-soft)') {
            const rows = this.fattyAcidProfileRows;
            const maxFattyAcidValue = rows.reduce((maxValue, row) => Math.max(maxValue, this.number(row.value)), 0);

            if (maxFattyAcidValue <= 0) {
                return `width: 0%; background: linear-gradient(90deg, color-mix(in srgb, ${color} 72%, white 28%) 0%, ${color} 100%);`;
            }

            const width = Math.max(0, Math.min(100, (this.number(value) / maxFattyAcidValue) * 100));

            return `width: ${width}%; background: linear-gradient(90deg, color-mix(in srgb, ${color} 72%, white 28%) 0%, ${color} 100%);`;
        },

        qualityTargetZone(key) {
            const zones = {
                unmolding_firmness: { start: 45, end: 70 },
                cured_hardness: { start: 45, end: 70 },
                longevity: { start: 40, end: 70 },
                cleansing_strength: { start: 0, end: 40 },
                mildness: { start: 50, end: 100 },
                bubble_volume: { start: 25, end: 55 },
                creamy_lather: { start: 25, end: 55 },
                lather_stability: { start: 25, end: 55 },
                conditioning_feel: { start: 35, end: 65 },
                dos_risk: { start: 0, end: 20 },
                slime_risk: { start: 0, end: 20 },
                shrinkage_risk: { start: 0, end: 20 },
                cure_speed: { start: 35, end: 60 },
                iodine: { start: 41, end: 70 },
                ins: { start: 136, end: 165 },
            };

            return zones[key] ?? null;
        },

        targetZoneStyle(key) {
            if (!this.isQualityScored(key)) {
                return null;
            }

            const zone = this.qualityTargetZone(key);

            if (!zone) {
                return null;
            }

            return `left: ${zone.start}%; width: ${Math.max(0, zone.end - zone.start)}%;`;
        },

        fattyAcidGroupDefinitions() {
            return [
                { key: 'vs', shortLabel: 'VS', label: 'Quick-cleansing saturated fats', color: 'oklch(0.68 0.15 88)', softColor: 'oklch(0.94 0.05 88)', textColor: 'oklch(0.42 0.10 78)' },
                { key: 'hs', shortLabel: 'HS', label: 'Hard saturated fats', color: 'oklch(0.60 0.13 28)', softColor: 'oklch(0.93 0.04 28)', textColor: 'oklch(0.42 0.10 28)' },
                { key: 'mu', shortLabel: 'MU', label: 'Monounsaturated fats', color: 'oklch(0.58 0.09 145)', softColor: 'oklch(0.93 0.03 145)', textColor: 'oklch(0.39 0.07 145)' },
                { key: 'pu', shortLabel: 'PU', label: 'Polyunsaturated fats', color: 'oklch(0.59 0.09 240)', softColor: 'oklch(0.93 0.03 240)', textColor: 'oklch(0.40 0.07 240)' },
                { key: 'sp', shortLabel: 'SP', label: 'Special lather fats', color: 'oklch(0.60 0.08 315)', softColor: 'oklch(0.93 0.03 315)', textColor: 'oklch(0.42 0.06 315)' },
            ];
        },

        fattyAcidGroupSegments() {
            const groups = this.backendCalculation?.properties?.fatty_acid_groups ?? {};
            const segments = this.fattyAcidGroupDefinitions()
                .map((segment) => ({
                    ...segment,
                    value: groups[segment.key] ?? 0,
                }))
                .filter((segment) => this.number(segment.value) > 0);

            const total = segments.reduce((sum, segment) => sum + this.number(segment.value), 0);

            return segments.map((segment) => ({
                ...segment,
                percent: total > 0 ? (this.number(segment.value) / total) * 100 : 0,
            }));
        },

        fattyAcidGroupColorFor(key) {
            const groupByFattyAcid = {
                caprylic: 'vs',
                capric: 'vs',
                lauric: 'vs',
                myristic: 'vs',
                palmitic: 'hs',
                stearic: 'hs',
                arachidic: 'hs',
                behenic: 'hs',
                lignoceric: 'hs',
                oleic: 'mu',
                palmitoleic: 'mu',
                gondoic: 'mu',
                erucic: 'mu',
                nervonic: 'mu',
                linoleic: 'pu',
                linolenic: 'pu',
                gamma_linolenic: 'pu',
                punicic: 'pu',
                ricinoleic: 'sp',
            };
            const groupKey = groupByFattyAcid[key];

            return this.fattyAcidGroupDefinitions().find((group) => group.key === groupKey)?.color ?? 'var(--color-ink-soft)';
        },

        fattyAcidChemistrySummaryRows() {
            const quality = this.qualityMetrics();
            const groups = this.backendCalculation?.properties?.fatty_acid_groups ?? {};
            const rows = [];
            const hasQualityValue = (key) => Object.prototype.hasOwnProperty.call(quality, key);

            if (hasQualityValue('iodine')) {
                rows.push({
                    key: 'iodine',
                    label: 'Iodine',
                    value: this.format(quality.iodine, 1),
                    bracket: this.qualityTargetRangeLabel('iodine'),
                });
            }

            if (hasQualityValue('ins')) {
                rows.push({
                    key: 'ins',
                    label: 'INS',
                    value: this.format(quality.ins, 1),
                    bracket: this.qualityTargetRangeLabel('ins'),
                });
            }

            const saturated = this.number(groups.sat ?? (this.number(groups.vs) + this.number(groups.hs)));
            const unsaturated = this.number(groups.unsat ?? (this.number(groups.mu) + this.number(groups.pu) + this.number(groups.sp)));

            if (saturated > 0 || unsaturated > 0) {
                rows.push({
                    key: 'sat_unsat',
                    label: 'Sat / Unsat',
                    value: `${this.format(saturated, 0)} / ${this.format(unsaturated, 0)}`,
                    bracket: this.fattyAcidSatUnsatRatio(saturated, unsaturated),
                });
            }

            return rows;
        },

        fattyAcidSatUnsatRatio(saturated, unsaturated) {
            if (unsaturated <= 0) {
                return saturated > 0 ? 'Saturated only' : 'No ratio yet';
            }

            return `${this.format(saturated / unsaturated, 2)}:1`;
        },

        get totalSummaryCards() {
            if (this.isCosmeticFormula) {
                return [
                    {
                        id: 'formula-total',
                        label: 'Formula total',
                        value: `${this.formatPercentageTotal(this.totalOilPercentage())}%`,
                    },
                    {
                        id: 'batch-weight',
                        label: 'Batch weight',
                        value: `${this.format(this.oilWeight, 3)} ${this.oilUnit}`,
                    },
                    {
                        id: 'ingredient-weight',
                        label: 'Ingredient weight',
                        value: `${this.format(this.cosmeticFormulaWeightTotal(), 3)} ${this.oilUnit}`,
                    },
                    {
                        id: 'phase-count',
                        label: 'Phases',
                        value: `${this.phaseOrder.length}`,
                    },
                ];
            }

            const producedGlycerineWeight = this.backendCalculation?.lye?.selected?.glycerine_weight
                ?? this.lyeBreakdown().glycerine_weight;
            const serverBasis = this.labelingBasis?.cured_weight == null ? null : this.labelingBasis;
            const wetWeight = serverBasis ? this.number(serverBasis.formula_weight) : this.finalBatchWeight();
            const curedWeight = serverBasis ? this.number(serverBasis.cured_weight) : this.curedBatchWeight();
            const calculatedMass = (value) => (this.isCalculationRejected
                ? '—'
                : `${this.format(value, this.calculatedMassDecimals(value))} ${this.oilUnit}`);

            return [
                {
                    id: 'additives-total',
                    label: this.t('soap.additives_total'),
                    value: `${this.format(this.totalAdditionPercentage(), 1)}%`,
                },
                {
                    id: 'produced-glycerine',
                    label: this.t('soap.glycerine'),
                    value: calculatedMass(producedGlycerineWeight),
                },
                {
                    id: 'wet-weight',
                    label: this.t('soap.wet_weight'),
                    value: calculatedMass(wetWeight),
                },
                {
                    id: 'cured-weight',
                    label: this.t('soap.cured_weight'),
                    value: calculatedMass(curedWeight),
                },
            ];
        },

        get labelingBasis() {
            return this.backendLabeling?.basis ?? null;
        },

        get ingredientListVariants() {
            return this.backendLabeling?.list_variants ?? [];
        },

        get defaultIngredientListVariantKey() {
            return this.backendLabeling?.default_variant_key ?? 'saponified_with_superfat';
        },

        get activeIngredientListVariantKey() {
            const variantKeys = this.ingredientListVariants.map((variant) => variant.key);

            return variantKeys.includes(this.selectedIngredientListVariantKey)
                ? this.selectedIngredientListVariantKey
                : this.defaultIngredientListVariantKey;
        },

        get activeIngredientListVariant() {
            return this.ingredientListVariants.find((variant) => variant.key === this.activeIngredientListVariantKey)
                ?? this.ingredientListVariants[0]
                ?? null;
        },

        get defaultIngredientListVariant() {
            return this.ingredientListVariants.find((variant) => variant.key === this.defaultIngredientListVariantKey)
                ?? this.ingredientListVariants[0]
                ?? null;
        },

        get generatedIngredientListText() {
            return this.activeIngredientListVariant?.final_label_text
                ?? this.backendLabeling?.final_label_text
                ?? '';
        },

        get ingredientListVariantHelperText() {
            if (this.isCosmeticFormula) {
                return this.t('output.lists.cosmetic_generated_help');
            }

            return this.activeIngredientListVariantKey === 'incorporated_ingredients'
                ? this.t('output.lists.soap_as_added_help')
                : this.t('output.lists.soap_saponified_help');
        },

        get cosmeticOutputIngredientRows() {
            if (!this.isCosmeticFormula) {
                return [];
            }

            return this.phaseOrder
                .flatMap((phase) => (this.phaseItems[phase.key] ?? []).map((row) => {
                    const percentage = this.number(row.percentage);
                    const weight = this.number(this.rowWeight(row));

                    return {
                        id: `cosmetic-output-${phase.key}-${row.id}`,
                        name: row.name,
                        inci_name: row.inci_name,
                        label_name: row.inci_name || row.name,
                        common_name: row.name,
                        phase: phase.name ?? this.humanizeKey(phase.key),
                        percentage,
                        weight,
                    };
                }))
                .filter((row) => row.percentage > 0 || row.weight > 0)
                .sort((left, right) => {
                    if (right.percentage !== left.percentage) {
                        return right.percentage - left.percentage;
                    }

                    if (right.weight !== left.weight) {
                        return right.weight - left.weight;
                    }

                    return String(left.name ?? '').localeCompare(String(right.name ?? ''));
                });
        },

        get cosmeticOutputIngredientTotalWeight() {
            return this.cosmeticOutputIngredientRows.reduce((sum, row) => sum + this.number(row.weight), 0);
        },

        get cosmeticOutputIngredientTotalPercent() {
            return this.cosmeticOutputIngredientRows.reduce((sum, row) => sum + this.number(row.percentage), 0);
        },

        get ingredientListBasisHash() {
            return this.backendLabeling?.ingredient_list_basis_hash ?? '';
        },

        get generatedPlainLanguageListText() {
            return this.activeIngredientListVariant?.plain_label_text
                ?? this.backendLabeling?.plain_language_list?.final_label_text
                ?? '';
        },

        get finalIngredientListIsOutdated() {
            return Boolean(
                this.finalIngredientList
                && this.finalIngredientListBasisHash
                && this.ingredientListBasisHash
                && this.finalIngredientListBasisHash !== this.ingredientListBasisHash,
            );
        },

        get finalPlainIngredientListIsOutdated() {
            return Boolean(
                this.finalPlainIngredientList
                && this.finalPlainIngredientListBasisHash
                && this.ingredientListBasisHash
                && this.finalPlainIngredientListBasisHash !== this.ingredientListBasisHash,
            );
        },

        get printIngredientListText() {
            return this.finalIngredientList || this.curedSoapOutputListText;
        },

        get printPlainIngredientListText() {
            return this.finalPlainIngredientList || this.generatedPlainLanguageListText;
        },

        get generatedIngredientRows() {
            return this.activeIngredientListVariant?.ingredient_rows
                ?? this.backendLabeling?.ingredient_rows
                ?? [];
        },

        get declarationRows() {
            return this.activeIngredientListVariant?.declaration_rows
                ?? this.backendLabeling?.declaration_rows
                ?? [];
        },

        get labelingWarnings() {
            return this.backendLabeling?.warnings ?? [];
        },

        get restrictionSummary() {
            return this.backendRestrictions?.summary ?? {
                status: 'pass',
                fail_count: 0,
                warning_count: 0,
                pass_count: 0,
                row_count: 0,
            };
        },

        get restrictionRows() {
            return this.backendRestrictions?.rows ?? [];
        },

        get restrictionWarnings() {
            return this.backendRestrictions?.warnings ?? [];
        },

        get restrictionsRequireAttention() {
            return !['pass', 'unverified', 'not_checked'].includes(this.restrictionSummary.status)
                || this.restrictionWarnings.length > 0;
        },

        get restrictionsNotChecked() {
            return this.restrictionSummary.status === 'not_checked';
        },

        get restrictionsHaveNoRules() {
            return this.restrictionSummary.status === 'no_rules';
        },

        get restrictionsHaveStarterRules() {
            return this.restrictionRows.some((row) => row.status === 'starter_rule');
        },

        get declarationRowsRequireAttention() {
            return this.curedSoapDeclarationRows.some((row) => Boolean(row.exceeds_threshold));
        },

        get restrictionRegimeLabel() {
            return this.backendRestrictions?.regime?.label ?? this.selectedRegulatoryRegimeRecord?.name ?? 'Selected regime';
        },

        get restrictionBasisLabel() {
            return this.backendRestrictions?.basis?.label ?? 'Current formula basis';
        },

        get restrictionSummaryLabel() {
            return {
                fail: 'Needs correction',
                warning: 'Needs review',
                pass: 'No restriction flags',
                unverified: this.t('output.restrictions.starter_rules_only'),
                not_checked: this.t('output.market_check.not_checked'),
                no_rules: this.t('output.restrictions.no_rules'),
            }[this.restrictionSummary.status] ?? 'No restriction flags';
        },

        restrictionSummaryStyle(status) {
            return {
                fail: 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]',
                warning: 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]',
                pass: 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]',
            }[status] ?? 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]';
        },

        restrictionStatusStyle(status) {
            return {
                prohibited: 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]',
                over_limit: 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]',
                unknown_concentration: 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]',
                watch: 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]',
                no_limit_recorded: 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]',
                within_limit: 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]',
            }[status] ?? 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]';
        },

        get curedSoapOutputListText() {
            if (this.isCosmeticFormula) {
                return this.generatedIngredientListText;
            }

            if (this.activeIngredientListVariantKey === 'incorporated_ingredients') {
                return this.activeIngredientListVariant?.display_final_label_text ?? this.generatedIngredientListText;
            }

            const ingredientLabels = this.curedSoapIngredientRows.map((row) => row.display_label || row.label);
            const allergenLabels = this.curedSoapDeclarationRows
                .filter((row) => row.included_in_inci)
                .map((row) => row.display_label || row.label);

            return [...ingredientLabels, ...allergenLabels].join(', ');
        },

        get curedSoapOutputBasisWeight() {
            if (this.isCosmeticFormula) {
                return this.number(this.oilWeight);
            }

            // A liquid soap is not cured: its label basis is the finished batch.
            if (this.isLiquidSoapFormula) {
                return this.number(this.labelingBasis?.formula_weight ?? this.backendCalculation?.liquid_process?.final_weight);
            }

            return this.number(this.labelingBasis?.cured_weight ?? this.curedBatchWeight());
        },

        get curedWaterPercentageLabel() {
            const value = this.number(this.curedWaterPercentage);
            const decimals = Number.isInteger(value) ? 0 : (Number.isInteger(value * 10) ? 1 : 2);

            return this.format(value, decimals);
        },

        get curedSoapResidualWaterWeight() {
            if (this.isCosmeticFormula || this.isLiquidSoapFormula) {
                return 0;
            }

            return this.number(
                this.labelingBasis?.residual_water_weight
                ?? (this.curedSoapOutputBasisWeight * (this.number(this.curedWaterPercentage) / 100)),
            );
        },

        get incorporatedSoapIngredientRows() {
            if (this.isCosmeticFormula) {
                return [];
            }

            return (this.ingredientListVariants.find((variant) => variant.key === 'incorporated_ingredients')
                ?.ingredient_rows ?? []).filter((row) => this.number(row.weight) > 0);
        },

        get incorporatedSoapIngredientTotalPercent() {
            return this.incorporatedSoapIngredientRows.reduce((sum, row) => sum + this.number(row.percent_of_formula), 0);
        },

        outputIngredientCommonName(row) {
            const label = String(row.label ?? '').trim().toUpperCase();
            const standaloneNames = {
                AQUA: 'water',
                GLYCERIN: 'glycerin',
                'SODIUM HYDROXIDE': 'sodium_hydroxide',
                'POTASSIUM HYDROXIDE': 'potassium_hydroxide',
            };
            const commonName = standaloneNames[label]
                ? this.t(`output.common.${standaloneNames[label]}`)
                : [...new Set(row.source_ingredients ?? [])].filter(Boolean).join(', ');

            return commonName.toUpperCase() === label ? '' : commonName;
        },

        get curedSoapIngredientRows() {
            if (this.isCosmeticFormula) {
                return this.generatedIngredientRows.map((row) => ({
                    ...row,
                    adjusted_weight: this.number(row.weight),
                    percent_of_cured_basis: this.number(row.percent_of_formula),
                }));
            }

            const curedBasisWeight = this.curedSoapOutputBasisWeight;
            const residualWaterWeight = this.curedSoapResidualWaterWeight;
            const variant = this.defaultIngredientListVariant ?? this.activeIngredientListVariant;
            const ingredientRows = variant
                ?.ingredient_rows
                ?.filter((row) => row?.kind !== 'lye')
                ?? [];

            if (this.isLiquidSoapFormula) {
                return this.liquidSoapOutputRows(ingredientRows, curedBasisWeight);
            }

            const freshLyeLiquidWeight = ingredientRows
                .reduce((sum, row) => sum + this.lyeLiquidProvenanceWeight(row), 0);
            const rows = ingredientRows
                .map((row) => {
                    const totalWeight = this.number(row.weight);
                    const lyeLiquidWeight = Math.min(totalWeight, this.lyeLiquidProvenanceWeight(row));
                    const nonLyeWeight = Math.max(0, totalWeight - lyeLiquidWeight);

                    return {
                        ...row,
                        adjusted_weight: nonLyeWeight + (freshLyeLiquidWeight > 0
                            ? residualWaterWeight * (lyeLiquidWeight / freshLyeLiquidWeight)
                            : 0),
                    };
                })
                .filter((row) => row.adjusted_weight > 0);

            return rows
                .map((row) => ({
                    ...row,
                    percent_of_cured_basis: curedBasisWeight > 0
                        ? (row.adjusted_weight / curedBasisWeight) * 100
                        : 0,
                }))
                .sort((left, right) => {
                    if (left.adjusted_weight === right.adjusted_weight) {
                        return left.label.localeCompare(right.label);
                    }

                    return right.adjusted_weight - left.adjusted_weight;
                });
        },

        /**
         * The liquid soap label rows as they sit in the finished batch. The
         * water is the water in the bottle, so nothing is swapped for residual
         * water.
         */
        liquidSoapOutputRows(ingredientRows, basisWeight) {
            return ingredientRows
                .map((row) => ({ ...row, adjusted_weight: this.number(row.weight) }))
                .filter((row) => row.adjusted_weight > 0)
                .map((row) => ({
                    ...row,
                    percent_of_cured_basis: basisWeight > 0 ? (row.adjusted_weight / basisWeight) * 100 : 0,
                }))
                .sort((left, right) => (left.adjusted_weight === right.adjusted_weight
                    ? left.label.localeCompare(right.label)
                    : right.adjusted_weight - left.adjusted_weight));
        },

        lyeLiquidProvenanceWeight(row) {
            if (Object.hasOwn(row, 'lye_liquid_weight')) {
                return this.number(row.lye_liquid_weight);
            }

            return row.label === 'AQUA' || row.kind === 'lye_liquid'
                ? this.number(row.weight)
                : 0;
        },

        get curedSoapIngredientTotalWeight() {
            return this.curedSoapIngredientRows.reduce((sum, row) => sum + this.number(row.adjusted_weight), 0);
        },

        get curedSoapIngredientTotalPercent() {
            return this.curedSoapIngredientRows.reduce((sum, row) => sum + this.number(row.percent_of_cured_basis), 0);
        },

        get curedSoapDeclarationRows() {
            if (this.isCosmeticFormula) {
                return this.declarationRows.map((row) => ({
                    ...row,
                    adjusted_weight: this.oilWeight * (this.number(row.percent_of_formula) / 100),
                    percent_of_cured_basis: this.number(row.percent_of_formula),
                }));
            }

            const formulaWeight = this.number(this.labelingBasis?.formula_weight ?? this.finalBatchWeight());
            const curedBasisWeight = this.curedSoapOutputBasisWeight;

            return ((this.defaultIngredientListVariant ?? this.activeIngredientListVariant)?.declaration_rows ?? [])
                .map((row) => {
                    const declarationWeight = formulaWeight * (this.number(row.percent_of_formula) / 100);
                    const percentOfCuredBasis = row.percent_of_cured_basis === null || row.percent_of_cured_basis === undefined
                        ? (curedBasisWeight > 0 ? (declarationWeight / curedBasisWeight) * 100 : 0)
                        : this.number(row.percent_of_cured_basis);

                    return {
                        ...row,
                        adjusted_weight: declarationWeight,
                        percent_of_cured_basis: percentOfCuredBasis,
                    };
                })
                .sort((left, right) => {
                    if (left.adjusted_weight === right.adjusted_weight) {
                        return left.label.localeCompare(right.label);
                    }

                    return right.adjusted_weight - left.adjusted_weight;
                });
        },

        get curedSoapAllergenRows() {
            return this.curedSoapDeclarationRows.filter((row) => row.included_in_inci);
        },

        formatCompositionPercentage(value) {
            const percentage = this.number(value);

            return percentage > 0 && percentage < 0.001
                ? `<${this.format(0.001, 3)}%`
                : `${this.format(percentage, 3)}%`;
        },

        outputRowKindLabel(row) {
            if (row?.included_in_ingredient_list === false) {
                return this.t('liquid.composition_only');
            }

            if (row?.label === 'AQUA') {
                return this.isLiquidSoapFormula ? 'Water' : 'Residual water';
            }

            if (row?.kind === 'mixed_saponified_superfat') {
                return 'Soap + superfat share';
            }

            if (row?.kind === 'theoretical_superfat') {
                return 'Superfat share';
            }

            if (row?.kind === 'saponified_oil') {
                return 'Saponified oil';
            }

            if (row?.kind === 'parfum' || row?.label === 'PARFUM') {
                return 'Aromatic blend';
            }

            if (row?.kind === 'derived') {
                return 'Reaction by-product';
            }

            return 'Ingredient';
        },

        selectIngredientListVariant(key) {
            this.selectedIngredientListVariantKey = key;
            this.ingredientListCopyMessage = '';
            this.ingredientListCopyTarget = '';
        },

        useGeneratedIngredientListAsFinal() {
            this.ingredientListUndo = {
                target: 'inci',
                value: this.finalIngredientList ?? '',
                basisHash: this.finalIngredientListBasisHash ?? '',
                message: this.t('messages.ingredient_list_replaced'),
            };
            this.finalIngredientList = this.curedSoapOutputListText;
            this.finalIngredientListBasisHash = this.ingredientListBasisHash;
        },

        useGeneratedPlainIngredientListAsFinal() {
            this.ingredientListUndo = {
                target: 'plain',
                value: this.finalPlainIngredientList ?? '',
                basisHash: this.finalPlainIngredientListBasisHash ?? '',
                message: this.t('messages.ingredient_list_replaced'),
            };
            this.finalPlainIngredientList = this.generatedPlainLanguageListText;
            this.finalPlainIngredientListBasisHash = this.ingredientListBasisHash;
        },

        touchFinalIngredientList() {
            this.finalIngredientListBasisHash = this.ingredientListBasisHash;

            if (this.ingredientListUndo?.target === 'inci') {
                this.ingredientListUndo = null;
            }
        },

        touchFinalPlainIngredientList() {
            this.finalPlainIngredientListBasisHash = this.ingredientListBasisHash;

            if (this.ingredientListUndo?.target === 'plain') {
                this.ingredientListUndo = null;
            }
        },

        clearFinalIngredientList() {
            this.ingredientListUndo = {
                target: 'inci',
                value: this.finalIngredientList ?? '',
                basisHash: this.finalIngredientListBasisHash ?? '',
                message: this.t('messages.ingredient_list_cleared'),
            };
            this.finalIngredientList = '';
            this.finalIngredientListBasisHash = '';
        },

        clearFinalPlainIngredientList() {
            this.ingredientListUndo = {
                target: 'plain',
                value: this.finalPlainIngredientList ?? '',
                basisHash: this.finalPlainIngredientListBasisHash ?? '',
                message: this.t('messages.ingredient_list_cleared'),
            };
            this.finalPlainIngredientList = '';
            this.finalPlainIngredientListBasisHash = '';
        },

        undoIngredientListChange() {
            const undo = this.ingredientListUndo;

            if (!undo || !['inci', 'plain'].includes(undo.target)) {
                return false;
            }

            if (undo.target === 'inci') {
                this.finalIngredientList = undo.value;
                this.finalIngredientListBasisHash = undo.basisHash;
            } else {
                this.finalPlainIngredientList = undo.value;
                this.finalPlainIngredientListBasisHash = undo.basisHash;
            }

            this.ingredientListUndo = null;

            return true;
        },

        syncIngredientListVariantSelection() {
            const variantKeys = this.ingredientListVariants.map((variant) => variant.key);

            if (variantKeys.length === 0) {
                this.selectedIngredientListVariantKey = this.defaultIngredientListVariantKey;

                return;
            }

            if (!variantKeys.includes(this.selectedIngredientListVariantKey)) {
                this.selectedIngredientListVariantKey = this.defaultIngredientListVariantKey;
            }
        },

        declarationStatusClasses(row) {
            if (!row?.exceeds_threshold) {
                return 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]';
            }

            return row?.suppressed_by_existing_label
                ? 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'
                : 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]';
        },

        get fattyAcidProfileRows() {
            const profile = this.backendCalculation?.properties?.fatty_acid_profile ?? this.averageFattyAcidProfile();
            const labels = this.fattyAcidLabels();

            return Object.entries(labels)
                .map(([key, label]) => ({
                    key,
                    label,
                    value: profile[key] ?? 0,
                    color: this.fattyAcidGroupColorFor(key),
                }))
                .filter((row) => this.number(row.value) >= 0.5);
        },

        get hasFattyAcidProfileData() {
            const profile = this.backendCalculation?.properties?.fatty_acid_profile ?? this.averageFattyAcidProfile();

            return Object.values(profile).some((value) => this.number(value) > 0);
        },

        get hasQualityMetricsData() {
            return Object.keys(this.qualityMetrics()).length > 0;
        },

        qualityMetrics() {
            return this.backendCalculation?.properties?.qualities ?? {};
        },

        qualityLabel(value) {
            const numeric = this.number(value);

            if (numeric < 20) return this.t('qualities.very_low');
            if (numeric < 40) return this.t('qualities.low');
            if (numeric < 60) return this.t('qualities.moderate');
            if (numeric < 80) return this.t('qualities.high');

            return this.t('qualities.very_high');
        },

        qualityExplanation(key, value) {
            const numeric = this.number(value);

            if (key === 'cleansing_strength') {
                if (numeric < 20) return this.t('qualities.cleansing_low');
                if (numeric <= 40) return this.t('qualities.cleansing_balanced');
                if (numeric < 65) return this.t('qualities.cleansing_high');

                return this.t('qualities.cleansing_very_high');
            }

            if (key === 'shrinkage_risk') {
                return this.t(numeric < 35 ? 'qualities.shrinkage_low' : 'qualities.shrinkage_high');
            }

            const ranges = {
                unmolding_firmness: [
                    [20, 'Likely soft at unmolding. Expect more patience, support molds, or a longer wait before cutting.'],
                    [40, 'Reasonably manageable, but still on the softer side when first unmolded.'],
                    [65, 'Should unmold with decent confidence for most everyday bar formulas.'],
                    [101, 'Very quick to firm up and likely easy to unmold early.'],
                ],
                cured_hardness: [
                    [25, 'Will likely remain a softer bar even after cure.'],
                    [45, 'Moderately firm after cure, but not especially hard.'],
                    [70, 'A solid cured bar with good firmness for regular handling and use.'],
                    [101, 'Very firm cured bar territory.'],
                ],
                longevity: [
                    [25, 'May disappear quickly in use, especially if kept wet between washes.'],
                    [45, 'Average staying power in the shower or at the sink.'],
                    [70, 'Should hold up well with decent lifespan in normal use.'],
                    [101, 'Strong longevity profile for a long-lasting bar.'],
                ],
                mildness: [
                    [20, 'Low mildness. This may feel harsh unless the formula intent is very cleansing.'],
                    [40, 'Somewhat mild, but still more functional than gentle.'],
                    [65, 'A balanced mildness level for many everyday soaps.'],
                    [101, 'Very gentle-leaning profile.'],
                ],
                bubble_volume: [
                    [20, 'Low big-bubble output. Foam may feel restrained or compact.'],
                    [45, 'Moderate bubble lift.'],
                    [70, 'Good bubbly character.'],
                    [101, 'Very bubbly and quick-foaming.'],
                ],
                creamy_lather: [
                    [20, 'Lather may feel light rather than creamy.'],
                    [45, 'Some creaminess, but not especially rich.'],
                    [70, 'A nicely creamy foam profile.'],
                    [101, 'Very creamy lather character.'],
                ],
                lather_stability: [
                    [20, 'Foam may collapse fairly quickly.'],
                    [45, 'Moderate stability once lather is built.'],
                    [70, 'Good staying power in the lather.'],
                    [101, 'Very stable foam profile.'],
                ],
                conditioning_feel: [
                    [20, 'More functional than cushiony in skin feel.'],
                    [45, 'Moderately conditioned skin feel.'],
                    [70, 'Should leave a pleasant conditioned feel after washing.'],
                    [101, 'Strong conditioning feel profile.'],
                ],
                dos_risk: [
                    [20, 'Low DOS tendency from the fatty-acid profile.'],
                    [40, 'Some DOS sensitivity. Storage and antioxidants matter more.'],
                    [60, 'Elevated DOS risk. Consider antioxidants, fresher oils, and careful storage.'],
                    [101, 'High DOS risk territory. Formula and storage discipline matter a lot.'],
                ],
                slime_risk: [
                    [20, 'Low slime tendency.'],
                    [40, 'A little early-use sliminess is possible.'],
                    [60, 'Noticeable slime tendency is plausible, especially in high-oleic styles.'],
                    [101, 'Strong slime tendency signal, often seen in castile-like profiles before long cure.'],
                ],
                cure_speed: [
                    [20, 'Slow cure expected. This style benefits from patience.'],
                    [40, 'Moderate cure speed, but not especially fast.'],
                    [65, 'Reasonable cure progression for most bars.'],
                    [101, 'Fast-curing profile.'],
                ],
            };

            const entries = ranges[key];

            if (!entries) {
                return null;
            }

            return entries.find(([limit]) => numeric < limit)?.[1] ?? null;
        },

        latherProfileSummary() {
            const quality = this.qualityMetrics();

            if (quality.bubble_volume >= 60 && quality.creamy_lather < 45) {
                return 'Quick and bubbly';
            }

            if (quality.creamy_lather >= 55 && quality.lather_stability >= 50) {
                return 'Creamy and stable';
            }

            if (quality.bubble_volume < 30 && quality.creamy_lather < 35) {
                return 'Low bubbles, gentle foam';
            }

            return 'Balanced lather';
        },

        barAndCureQualityRows() {
            const quality = this.qualityMetrics();

            return [
                [this.t('qualities.metrics.unmolding_firmness'), 'unmolding_firmness'],
                [this.t('qualities.hardness_four_weeks'), 'cured_hardness'],
                [this.t('qualities.metrics.longevity'), 'longevity'],
                [this.t('qualities.metrics.cure_speed'), 'cure_speed'],
                [this.t('qualities.metrics.dos_risk'), 'dos_risk'],
                [this.t('qualities.shrinkage_label'), 'shrinkage_risk'],
            ].filter(([, key]) => this.isQualityApplicable(key)).map(([label, key]) => ({
                label,
                key,
                value: quality[key],
                level: this.qualityLabel(quality[key]),
                explanation: this.qualityExplanation(key, quality[key]),
            }));
        },

        latherAndFeelQualityRows() {
            const quality = this.qualityMetrics();

            return [
                [this.t('qualities.metrics.cleansing_strength'), 'cleansing_strength'],
                [this.t('qualities.metrics.mildness'), 'mildness'],
                [this.t('qualities.metrics.bubble_volume'), 'bubble_volume'],
                [this.t('qualities.metrics.creamy_lather'), 'creamy_lather'],
                [this.t('qualities.metrics.lather_stability'), 'lather_stability'],
                [this.t('qualities.metrics.conditioning_feel'), 'conditioning_feel'],
                [this.t('qualities.metrics.slime_risk'), 'slime_risk'],
            ].filter(([, key]) => this.isQualityApplicable(key)).map(([label, key]) => ({
                label,
                key,
                value: quality[key],
                level: this.qualityLabel(quality[key]),
                explanation: this.qualityExplanation(key, quality[key]),
            }));
        },

        /**
         * Liquid soap shows one row of fatty-acid tendencies with a level,
         * no bar-soap targets: the cook, dilution and neutralization decide
         * the finished soap.
         */
        liquidQualityRows() {
            const quality = this.qualityMetrics();

            return ['dilution_ease', 'bubble_volume', 'creamy_lather', 'lather_stability']
                .filter((key) => quality[key] !== undefined && this.isQualityApplicable(key))
                .map((key) => ({
                    key,
                    label: this.t(`qualities.metrics.${key}`),
                    value: quality[key],
                    level: this.qualityLabel(quality[key]),
                }));
        },

        qualityFlags() {
            const quality = this.qualityMetrics();
            const groups = this.backendCalculation?.properties?.fatty_acid_groups ?? {};
            const vs = groups.vs ?? 0;
            const hs = groups.hs ?? 0;
            const mu = groups.mu ?? 0;
            const flags = this.backendCalculationWarningFlags();

            if (this.isQualityApplicable('cure_speed') && quality.cure_speed < 35) {
                flags.push({
                    label: this.t('qualities.slow_cure'),
                    helpKey: 'soap.qualities.cure',
                    explanation: this.t('qualities.slow_cure_help'),
                });
            }

            if (this.isQualityApplicable('dos_risk') && quality.dos_risk >= 35) {
                flags.push({
                    label: this.t('qualities.metrics.dos_risk'),
                    helpKey: 'soap.qualities.dos',
                    explanation: this.t('qualities.dos_help'),
                });
            }

            if (this.isQualityApplicable('slime_risk') && quality.slime_risk >= 35) {
                flags.push({
                    label: this.t('qualities.metrics.slime_risk'),
                    helpKey: 'soap.qualities.cure',
                    explanation: this.t('qualities.slime_help'),
                });
            }

            if (this.isQualityScored('cleansing_strength') && quality.cleansing_strength > 40) {
                flags.push({
                    label: this.t('qualities.high_cleansing'),
                    explanation: this.t('qualities.high_cleansing_help'),
                });
            }

            if (this.isQualityScored('shrinkage_risk') && quality.shrinkage_risk >= 35) {
                flags.push({
                    label: this.t('qualities.shrinkage_label'),
                    explanation: this.t('qualities.shrinkage_high'),
                });
            }

            if (this.isQualityApplicable('cure_speed') && this.isQualityApplicable('slime_risk') && mu > 65 && vs < 12 && hs < 20) {
                flags.push({
                    label: this.t('qualities.castile'),
                    helpKey: 'soap.qualities.cure',
                    explanation: this.t('qualities.castile_help'),
                });
            }

            return flags;
        },

        backendCalculationWarningFlags() {
            const warnings = this.backendCalculation?.properties?.warnings ?? [];
            const warningLabels = {
                high_koh_context_process_dependent: {
                    label: this.t('qualities.high_koh'),
                    helpKey: 'soap.qualities.liquid',
                    explanation: this.t('qualities.high_koh_help'),
                },
                effective_superfat_leaves_excess_lye: {
                    label: this.t('qualities.excess_lye'),
                    helpKey: 'soap.superfat',
                    explanation: this.t('qualities.excess_lye_help'),
                },
                negative_superfat_requires_neutralization_and_ph_control: {
                    label: this.t('qualities.negative_superfat'),
                    helpKey: 'soap.qualities.liquid',
                    explanation: this.t('qualities.negative_superfat_help'),
                },
                positive_liquid_superfat_may_cloud_or_separate: {
                    label: this.t('qualities.liquid_superfat'),
                    helpKey: 'soap.qualities.liquid',
                    explanation: this.t('qualities.liquid_superfat_help'),
                },
                high_polyunsaturated_dos_risk: {
                    label: this.t('qualities.high_polyunsaturated_dos'),
                    helpKey: 'soap.qualities.dos',
                    explanation: this.t('qualities.high_polyunsaturated_dos_help'),
                },
                very_high_polyunsaturated_dos_risk: {
                    label: this.t('qualities.very_high_dos'),
                    helpKey: 'soap.qualities.dos',
                    explanation: this.t('qualities.very_high_dos_help'),
                },
            };

            // The liquid soap process section already handles neutralization and says the values are tendencies.
            const liquidProcessWarnings = ['high_koh_context_process_dependent', 'negative_superfat_requires_neutralization_and_ph_control'];

            return warnings
                .filter((warning) => !(this.isLiquidSoapFormula && liquidProcessWarnings.includes(warning)))
                .map((warning) => warningLabels[warning] ?? null)
                .filter((warning) => warning !== null);
        },
    };
}
