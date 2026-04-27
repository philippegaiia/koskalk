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

        qualityTone(key, value) {
            const numeric = this.number(value);
            const zone = this.qualityTargetZone(key);

            if (!zone) {
                return 'ideal';
            }

            if (['dos_risk', 'slime_risk'].includes(key)) {
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
                high: 'var(--color-quality-high)',
                excess: 'var(--color-quality-excess)',
            };

            return colors[this.qualityTone(key, value)] ?? colors.ideal;
        },

        qualityLevelStyle(key, value) {
            return `--quality-tone: ${this.qualityToneColor(key, value)};`;
        },

        qualityCardStyle(key, value) {
            const tone = this.qualityTone(key, value);

            if (tone === 'ideal') {
                return 'border-[var(--color-line)] bg-white';
            }

            if (tone === 'excess') {
                return 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]';
            }

            return 'border-[var(--color-line-strong)] bg-[var(--color-accent-soft)]';
        },

        qualityTargetLabel(key) {
            const range = this.qualityTargetRangeLabel(key);

            return range ? `Target ${range}` : null;
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
                cleansing_strength: { start: 18, end: 40 },
                mildness: { start: 50, end: 75 },
                bubble_volume: { start: 25, end: 55 },
                creamy_lather: { start: 25, end: 55 },
                lather_stability: { start: 25, end: 55 },
                conditioning_feel: { start: 35, end: 65 },
                dos_risk: { start: 0, end: 20 },
                slime_risk: { start: 0, end: 20 },
                cure_speed: { start: 35, end: 60 },
                iodine: { start: 41, end: 70 },
                ins: { start: 136, end: 165 },
            };

            return zones[key] ?? null;
        },

        targetZoneStyle(key) {
            const zone = this.qualityTargetZone(key);

            if (!zone) {
                return null;
            }

            return `left: ${zone.start}%; width: ${Math.max(0, zone.end - zone.start)}%;`;
        },

        fattyAcidGroupSegments() {
            const groups = this.backendCalculation?.properties?.fatty_acid_groups ?? {};
            const segments = [
                { key: 'vs', shortLabel: 'VS', label: 'Quick-cleansing saturated fats', value: groups.vs ?? 0, color: '#a16207' },
                { key: 'hs', shortLabel: 'HS', label: 'Hard saturated fats', value: groups.hs ?? 0, color: '#7c5a3a' },
                { key: 'mu', shortLabel: 'MU', label: 'Monounsaturated fats', value: groups.mu ?? 0, color: '#5f6f52' },
                { key: 'pu', shortLabel: 'PU', label: 'Polyunsaturated fats', value: groups.pu ?? 0, color: '#5b6c8f' },
                { key: 'sp', shortLabel: 'SP', label: 'Special lather fats', value: groups.sp ?? 0, color: '#8b6f8f' },
            ].filter((segment) => this.number(segment.value) > 0);

            const total = segments.reduce((sum, segment) => sum + this.number(segment.value), 0);

            return segments.map((segment) => ({
                ...segment,
                percent: total > 0 ? (this.number(segment.value) / total) * 100 : 0,
            }));
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
                        value: `${this.format(this.totalOilPercentage(), 2)}%`,
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

            return [
                {
                    id: 'additives-total',
                    label: 'Additives (% base)',
                    value: `${this.format(this.totalAdditionPercentage(), 1)}%`,
                },
                {
                    id: 'produced-glycerine',
                    label: 'Produced glycerine',
                    value: `${this.format(this.backendCalculation?.lye?.selected?.glycerine_weight ?? this.lyeBreakdown().glycerine_weight, 0)} ${this.oilUnit}`,
                },
                {
                    id: 'wet-weight',
                    label: 'Wet weight',
                    value: `${this.format(this.finalBatchWeight(), 0)} ${this.oilUnit}`,
                },
                {
                    id: 'cured-weight',
                    label: 'Weight after cure',
                    value: `${this.format(this.curedBatchWeight(), 0)} ${this.oilUnit}`,
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

        get drySoapOutputListText() {
            if (this.isCosmeticFormula) {
                return this.generatedIngredientListText;
            }

            const ingredientLabels = this.drySoapIngredientRows.map((row) => row.label);
            const allergenLabels = this.drySoapDeclarationRows
                .filter((row) => row.included_in_inci)
                .map((row) => row.label);

            return [...ingredientLabels, ...allergenLabels].join(', ');
        },

        get drySoapOutputBasisWeight() {
            if (this.isCosmeticFormula) {
                return this.number(this.oilWeight);
            }

            return this.number(this.curedBatchWeight());
        },

        get drySoapResidualWaterWeight() {
            if (this.isCosmeticFormula) {
                return 0;
            }

            return this.drySoapOutputBasisWeight * 0.11;
        },

        get drySoapIngredientRows() {
            if (this.isCosmeticFormula) {
                return this.generatedIngredientRows.map((row) => ({
                    ...row,
                    adjusted_weight: this.number(row.weight),
                    percent_of_dry_basis: this.number(row.percent_of_formula),
                }));
            }

            const residualWaterWeight = this.drySoapResidualWaterWeight;
            const rows = (this.activeIngredientListVariant?.ingredient_rows ?? [])
                .map((row) => ({
                    ...row,
                    adjusted_weight: row.label === 'AQUA'
                        ? residualWaterWeight
                        : this.number(row.weight),
                }))
                .filter((row) => row.adjusted_weight > 0);
            const nonWaterTotalWeight = rows
                .filter((row) => row.label !== 'AQUA')
                .reduce((sum, row) => sum + row.adjusted_weight, 0);

            return rows
                .map((row) => ({
                    ...row,
                    percent_of_dry_basis: row.label === 'AQUA'
                        ? 11
                        : (nonWaterTotalWeight > 0 ? (row.adjusted_weight / nonWaterTotalWeight) * 89 : 0),
                }))
                .sort((left, right) => {
                    if (left.adjusted_weight === right.adjusted_weight) {
                        return left.label.localeCompare(right.label);
                    }

                    return right.adjusted_weight - left.adjusted_weight;
                });
        },

        get drySoapIngredientTotalWeight() {
            return this.drySoapIngredientRows.reduce((sum, row) => sum + this.number(row.adjusted_weight), 0);
        },

        get drySoapIngredientTotalPercent() {
            return this.drySoapIngredientRows.reduce((sum, row) => sum + this.number(row.percent_of_dry_basis), 0);
        },

        get drySoapDeclarationRows() {
            if (this.isCosmeticFormula) {
                return this.declarationRows.map((row) => ({
                    ...row,
                    adjusted_weight: this.oilWeight * (this.number(row.percent_of_formula) / 100),
                    percent_of_dry_basis: this.number(row.percent_of_formula),
                }));
            }

            const formulaWeight = this.number(this.labelingBasis?.formula_weight ?? this.finalBatchWeight());
            const ingredientTotalWeight = this.drySoapIngredientTotalWeight;

            return (this.activeIngredientListVariant?.declaration_rows ?? [])
                .map((row) => {
                    const declarationWeight = formulaWeight * (this.number(row.percent_of_formula) / 100);

                    return {
                        ...row,
                        adjusted_weight: declarationWeight,
                        percent_of_dry_basis: ingredientTotalWeight > 0
                            ? (declarationWeight / ingredientTotalWeight) * 100
                            : 0,
                    };
                })
                .sort((left, right) => {
                    if (left.adjusted_weight === right.adjusted_weight) {
                        return left.label.localeCompare(right.label);
                    }

                    return right.adjusted_weight - left.adjusted_weight;
                });
        },

        get drySoapAllergenRows() {
            return this.drySoapDeclarationRows.filter((row) => row.included_in_inci);
        },

        outputRowKindLabel(row) {
            if (row?.label === 'AQUA') {
                return 'Residual water';
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
            this.inciCopyMessage = '';
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
                }))
                .filter((row) => this.number(row.value) > 0);
        },

        get hasFattyAcidProfileData() {
            return this.fattyAcidProfileRows.length > 0;
        },

        get hasQualityMetricsData() {
            return Object.keys(this.qualityMetrics()).length > 0;
        },

        qualityMetrics() {
            return this.backendCalculation?.properties?.qualities ?? {};
        },

        qualityLabel(value) {
            const numeric = this.number(value);

            if (numeric < 20) return 'Very low';
            if (numeric < 40) return 'Low';
            if (numeric < 60) return 'Moderate';
            if (numeric < 80) return 'High';

            return 'Very high';
        },

        qualityExplanation(key, value) {
            const numeric = this.number(value);

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
                cleansing_strength: [
                    [20, 'Very gentle cleansing profile. Better for mild facial or low-stripping styles.'],
                    [40, 'Balanced cleansing for many body bars.'],
                    [65, 'Noticeably cleansing. Good for heavy-duty use but may feel drying on some skin.'],
                    [101, 'Extremely cleansing profile. Usually wants extra care with superfat and positioning.'],
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

        defaultQualityRows() {
            const quality = this.qualityMetrics();

            return [
                ['Unmolding firmness', 'unmolding_firmness'],
                ['Cured hardness', 'cured_hardness'],
                ['Longevity', 'longevity'],
                ['Cleansing strength', 'cleansing_strength'],
                ['Mildness', 'mildness'],
            ].map(([label, key]) => ({
                label,
                key,
                value: quality[key],
                level: this.qualityLabel(quality[key]),
                explanation: this.qualityExplanation(key, quality[key]),
            }));
        },

        advancedQualityRows() {
            const quality = this.qualityMetrics();

            return [
                ['Bubble volume', 'bubble_volume'],
                ['Creamy lather', 'creamy_lather'],
                ['Lather stability', 'lather_stability'],
                ['Conditioning feel', 'conditioning_feel'],
                ['DOS risk', 'dos_risk'],
                ['Slime risk', 'slime_risk'],
                ['Cure speed', 'cure_speed'],
            ].map(([label, key]) => ({
                label,
                key,
                value: quality[key],
                level: this.qualityLabel(quality[key]),
                explanation: this.qualityExplanation(key, quality[key]),
            }));
        },

        qualityFlags() {
            const quality = this.qualityMetrics();
            const groups = this.backendCalculation?.properties?.fatty_acid_groups ?? {};
            const vs = groups.vs ?? 0;
            const hs = groups.hs ?? 0;
            const mu = groups.mu ?? 0;
            const flags = [];

            if (quality.cure_speed < 35) {
                flags.push({
                    label: 'Slow cure',
                    explanation: 'This bar likely benefits from a longer cure before it shows its best hardness, feel, and lather.',
                });
            }

            if (quality.dos_risk >= 35) {
                flags.push({
                    label: 'DOS risk',
                    explanation: 'Higher unsaturation means storage conditions, fresh oils, and antioxidants matter more here.',
                });
            }

            if (quality.slime_risk >= 35) {
                flags.push({
                    label: 'Slime tendency',
                    explanation: 'High-oleic styles can feel slimy early on, especially before a long cure has finished smoothing them out.',
                });
            }

            if (quality.cleansing_strength >= 45) {
                flags.push({
                    label: 'High cleansing',
                    explanation: 'This should clean strongly, but may feel drying unless balanced carefully with superfat and formula positioning.',
                });
            }

            if (mu > 65 && vs < 12 && hs < 20) {
                flags.push({
                    label: 'Castile-like',
                    explanation: 'This profile behaves like a high-oleic soap: gentle and slow, often improving dramatically with a long cure.',
                });
            }

            return flags;
        },
    };
}
