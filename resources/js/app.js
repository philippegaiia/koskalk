import './bootstrap';

window.recipeWorkbench = (payload) => ({
    recipeId: payload.recipe?.id ?? null,
    draftVersionId: payload.recipe?.draft_version_id ?? null,
    formulaName: 'New Soap Formula',
    oilUnit: 'g',
    oilWeight: 1000,
    editMode: 'percentage',
    lyeType: 'naoh',
    kohPurity: 90,
    dualKohPercentage: 40,
    waterMode: 'percent_of_oils',
    waterValue: 38,
    superfat: 5,
    search: '',
    activeCategory: 'all',
    ifraProductCategories: payload.ifraProductCategories ?? [],
    selectedIfraProductCategoryId: payload.ifraProductCategories[0]?.id ?? null,
    ingredients: payload.ingredients ?? [],
    backendCalculation: payload.initialCalculation ?? null,
    calculationPreviewTimer: null,
    isPreviewingCalculation: false,
    phaseOrder: payload.phases ?? [],
    saveStatus: null,
    saveMessage: '',
    isSaving: false,
    phaseItems: {
        saponified_oils: [],
        additives: [],
        fragrance: [],
    },

    init() {
        this.applySavedDraft(payload.savedDraft ?? null);

        if (this.filteredIngredients.length > 0 && this.phaseItems.saponified_oils.length === 0) {
            this.addIngredient(this.filteredIngredients[0]);
        }

        ['oilWeight', 'lyeType', 'kohPurity', 'dualKohPercentage', 'waterMode', 'waterValue', 'superfat', 'selectedIfraProductCategoryId'].forEach((key) => {
            this.$watch(key, () => this.scheduleCalculationPreview());
        });

        this.$watch('phaseItems', () => this.scheduleCalculationPreview());
        this.scheduleCalculationPreview();
    },

    get categoryOptions() {
        return [
            { value: 'all', label: 'All' },
            { value: 'carrier_oil', label: 'Carrier Oils' },
            { value: 'essential_oil', label: 'Essential Oils' },
            { value: 'botanical_extract', label: 'Botanical Extracts' },
            { value: 'co2_extract', label: 'CO2 Extracts' },
            { value: 'colorant', label: 'Colorants' },
            { value: 'preservative', label: 'Preservatives' },
            { value: 'additive', label: 'Additives' },
        ];
    },

    get filteredIngredients() {
        const search = this.search.trim().toLowerCase();

        return this.ingredients.filter((ingredient) => {
            const matchesCategory = this.activeCategory === 'all' || ingredient.category === this.activeCategory;
            const matchesSearch = search === ''
                || ingredient.name.toLowerCase().includes(search)
                || (ingredient.inci_name ?? '').toLowerCase().includes(search);

            return matchesCategory && matchesSearch;
        });
    },

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
                    label: 'NaOH to weigh',
                    value: backendLye.selected?.naoh_weight ?? 0,
                });
            } else if (this.lyeType === 'koh') {
                cards.push({
                    id: 'koh-to-weigh',
                    label: this.kohPurity === 90 ? 'KOH to weigh (90%)' : 'KOH to weigh',
                    value: backendLye.selected?.koh_to_weigh ?? 0,
                });
            } else {
                cards.push(
                    {
                        id: 'dual-naoh-to-weigh',
                        label: 'NaOH to weigh',
                        value: backendLye.selected?.naoh_weight ?? 0,
                    },
                    {
                        id: 'dual-koh-to-weigh',
                        label: this.kohPurity === 90 ? 'KOH to weigh (90%)' : 'KOH to weigh',
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
                label: 'NaOH to weigh',
                value: lye.selected_naoh_weight,
            });
        } else if (this.lyeType === 'koh') {
            cards.push({
                id: 'koh-to-weigh',
                label: this.kohPurity === 90 ? 'KOH to weigh (90%)' : 'KOH to weigh',
                value: lye.koh_to_weigh,
            });
        } else {
            cards.push(
                {
                    id: 'dual-naoh-to-weigh',
                    label: 'NaOH to weigh',
                    value: lye.selected_naoh_weight,
                },
                {
                    id: 'dual-koh-to-weigh',
                    label: this.kohPurity === 90 ? 'KOH to weigh (90%)' : 'KOH to weigh',
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

    addIngredient(ingredient) {
        const targetPhase = this.targetPhaseForCategory(ingredient.category);
        const existingRow = this.phaseItems[targetPhase].find((row) => row.ingredient_version_id === ingredient.id);

        if (existingRow) {
            return;
        }

        this.phaseItems[targetPhase].push({
            id: `${ingredient.id}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            ingredient_version_id: ingredient.id,
            ingredient_id: ingredient.ingredient_id,
            name: ingredient.name,
            inci_name: ingredient.inci_name,
            category: ingredient.category,
            soap_inci_naoh_name: ingredient.soap_inci_naoh_name,
            soap_inci_koh_name: ingredient.soap_inci_koh_name,
            koh_sap_value: ingredient.koh_sap_value,
            naoh_sap_value: ingredient.naoh_sap_value,
            fatty_acid_profile: ingredient.fatty_acid_profile ?? {},
            percentage: targetPhase === 'saponified_oils' && this.phaseItems[targetPhase].length === 0 ? 100 : 0,
            note: '',
        });
    },

    applySavedDraft(draft) {
        if (!draft) {
            return;
        }

        this.recipeId = draft.recipe?.id ?? this.recipeId;
        this.draftVersionId = draft.recipe?.draft_version_id ?? this.draftVersionId;
        this.formulaName = draft.formulaName ?? this.formulaName;
        this.oilUnit = draft.oilUnit ?? this.oilUnit;
        this.oilWeight = this.number(draft.oilWeight ?? this.oilWeight);
        this.editMode = draft.editMode === 'weight' ? 'weight' : 'percentage';
        this.lyeType = ['naoh', 'koh', 'dual'].includes(draft.lyeType) ? draft.lyeType : this.lyeType;
        this.kohPurity = this.number(draft.kohPurity ?? this.kohPurity);
        this.dualKohPercentage = this.number(draft.dualKohPercentage ?? this.dualKohPercentage);
        this.waterMode = ['percent_of_oils', 'lye_ratio', 'lye_concentration'].includes(draft.waterMode) ? draft.waterMode : this.waterMode;
        this.waterValue = this.number(draft.waterValue ?? this.waterValue);
        this.superfat = this.number(draft.superfat ?? this.superfat);
        this.selectedIfraProductCategoryId = draft.selectedIfraProductCategoryId ?? this.selectedIfraProductCategoryId;
        this.phaseItems = {
            saponified_oils: draft.phaseItems?.saponified_oils ?? [],
            additives: draft.phaseItems?.additives ?? [],
            fragrance: draft.phaseItems?.fragrance ?? [],
        };
    },

    scheduleCalculationPreview() {
        if (this.calculationPreviewTimer) {
            clearTimeout(this.calculationPreviewTimer);
        }

        this.isPreviewingCalculation = true;
        this.calculationPreviewTimer = setTimeout(() => {
            this.refreshCalculationPreview();
        }, 120);
    },

    async refreshCalculationPreview() {
        try {
            const response = await this.$wire.previewCalculation(this.serializeDraft());

            if (response?.ok) {
                this.backendCalculation = response.calculation ?? null;
            }
        } catch (error) {
            this.backendCalculation = null;
        } finally {
            this.isPreviewingCalculation = false;
            this.calculationPreviewTimer = null;
        }
    },

    serializeDraft() {
        return {
            name: this.formulaName,
            oil_unit: this.oilUnit,
            oil_weight: this.oilWeight,
            editing_mode: this.editMode,
            lye_type: this.lyeType,
            koh_purity_percentage: this.kohPurity,
            dual_lye_koh_percentage: this.dualKohPercentage,
            water_mode: this.waterMode,
            water_value: this.waterValue,
            superfat: this.superfat,
            ifra_product_category_id: this.selectedIfraProductCategoryId,
            phase_items: {
                saponified_oils: this.oilRows.map((row) => this.serializeRow(row)),
                additives: this.additiveRows.map((row) => this.serializeRow(row)),
                fragrance: this.fragranceRows.map((row) => this.serializeRow(row)),
            },
        };
    },

    serializeRow(row) {
        return {
            id: row.id,
            ingredient_id: row.ingredient_id,
            ingredient_version_id: row.ingredient_version_id,
            percentage: this.number(row.percentage),
            weight: this.rowWeight(row),
            note: row.note ?? null,
        };
    },

    async saveDraft() {
        await this.persist('saveDraft');
    },

    async saveAsNewVersion() {
        await this.persist('saveAsNewVersion');
    },

    async duplicateFormula() {
        await this.persist('duplicateFormula');
    },

    async persist(method) {
        this.isSaving = true;
        this.saveStatus = null;
        this.saveMessage = '';

        try {
            const response = await this.$wire[method](this.serializeDraft());

            if (!response?.ok) {
                this.saveStatus = 'error';
                this.saveMessage = response?.message ?? 'The formula could not be saved.';

                return;
            }

            this.saveStatus = 'success';
            this.saveMessage = response.message ?? 'Formula saved.';

            if (response.draft) {
                this.applySavedDraft(response.draft);
            }

            if (response.redirect) {
                window.location.href = response.redirect;
            }
        } catch (error) {
            this.saveStatus = 'error';
            this.saveMessage = 'The formula could not be saved.';
        } finally {
            this.isSaving = false;
        }
    },

    removeIngredient(phaseKey, rowId) {
        this.phaseItems[phaseKey] = this.phaseItems[phaseKey].filter((row) => row.id !== rowId);
    },

    targetPhaseForCategory(category) {
        if (['essential_oil', 'botanical_extract', 'co2_extract', 'fragrance_oil'].includes(category)) {
            return 'fragrance';
        }

        return category === 'carrier_oil' ? 'saponified_oils' : 'additives';
    },

    rowWeight(row) {
        return this.oilWeight * (this.number(row.percentage) / 100);
    },

    updatePercentageFromWeight(row, weightValue) {
        const totalOilWeight = this.number(this.oilWeight);

        if (totalOilWeight <= 0) {
            row.percentage = 0;

            return;
        }

        row.percentage = (this.number(weightValue) / totalOilWeight) * 100;
    },

    updateOilPercentagesFromWeights(row, weightValue) {
        const weightsByRowId = new Map(
            this.oilRows.map((oilRow) => [oilRow.id, this.rowWeight(oilRow)]),
        );

        weightsByRowId.set(row.id, this.number(weightValue));

        const totalOilWeight = Array.from(weightsByRowId.values())
            .reduce((total, currentWeight) => total + this.number(currentWeight), 0);

        this.oilWeight = totalOilWeight;

        if (totalOilWeight <= 0) {
            this.oilRows.forEach((oilRow) => {
                oilRow.percentage = 0;
            });

            return;
        }

        this.oilRows.forEach((oilRow) => {
            const rowWeight = weightsByRowId.get(oilRow.id) ?? 0;

            oilRow.percentage = (this.number(rowWeight) / totalOilWeight) * 100;
        });
    },

    totalOilPercentage() {
        return this.sumPercentages(this.oilRows);
    },

    get oilPercentageIsBalanced() {
        return Math.abs(this.totalOilPercentage() - 100) <= 0.01;
    },

    get oilPercentageStatusLabel() {
        return this.oilPercentageIsBalanced ? 'Oil basis balanced' : 'Oil basis must reach 100%';
    },

    get hasSavedRecipe() {
        return this.recipeId !== null;
    },

    get hasPostReactionRows() {
        return this.additiveRows.length > 0 || this.fragranceRows.length > 0;
    },

    qualityBarStyle(value, color = 'var(--color-line-strong)') {
        const width = Math.max(0, Math.min(100, this.number(value)));

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
            { key: 'vs', shortLabel: 'VS', label: 'Quick-cleansing sats', value: groups.vs ?? 0, color: '#d97706' },
            { key: 'hs', shortLabel: 'HS', label: 'Hard sats', value: groups.hs ?? 0, color: '#92400e' },
            { key: 'mu', shortLabel: 'MU', label: 'Monounsaturated', value: groups.mu ?? 0, color: '#4d7c0f' },
            { key: 'pu', shortLabel: 'PU', label: 'Polyunsaturated', value: groups.pu ?? 0, color: '#4338ca' },
            { key: 'sp', shortLabel: 'SP', label: 'Special lather', value: groups.sp ?? 0, color: '#a21caf' },
        ].filter((segment) => this.number(segment.value) > 0);

        const total = segments.reduce((sum, segment) => sum + this.number(segment.value), 0);

        return segments.map((segment) => ({
            ...segment,
            percent: total > 0 ? (this.number(segment.value) / total) * 100 : 0,
        }));
    },

    get totalSummaryCards() {
        return [
            {
                id: 'oils-basis-total',
                label: 'Oils basis total',
                value: `${this.format(this.totalOilPercentage(), 1)}%`,
            },
            {
                id: 'post-reaction-additions',
                label: 'Post-reaction additions',
                value: `${this.format(this.totalAdditionPercentage(), 1)}% of oils`,
            },
            {
                id: 'produced-glycerine',
                label: 'Produced glycerine',
                value: `${this.format(this.backendCalculation?.lye?.selected?.glycerine_weight ?? this.lyeBreakdown().glycerine_weight, 1)} ${this.oilUnit}`,
            },
            {
                id: 'final-batch-estimate',
                label: 'Final batch estimate',
                value: `${this.format(this.finalBatchWeight(), 1)} ${this.oilUnit}`,
            },
        ];
    },

    get fattyAcidProfileRows() {
        const profile = this.backendCalculation?.properties?.fatty_acid_profile ?? this.averageFattyAcidProfile();
        const labels = {
            caprylic: 'Caprylic',
            capric: 'Capric',
            lauric: 'Lauric',
            myristic: 'Myristic',
            palmitic: 'Palmitic',
            palmitoleic: 'Palmitoleic',
            stearic: 'Stearic',
            ricinoleic: 'Ricinoleic',
            oleic: 'Oleic',
            linoleic: 'Linoleic',
            linolenic: 'Linolenic',
            arachidic: 'Arachidic',
            gondoic: 'Gondoic',
            behenic: 'Behenic',
            erucic: 'Erucic',
        };

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
            ['Iodine', 'iodine'],
            ['INS', 'ins'],
        ].map(([label, key]) => ({
            label,
            key,
            value: quality[key],
            level: ['iodine', 'ins'].includes(key) ? null : this.qualityLabel(quality[key]),
            explanation: ['iodine', 'ins'].includes(key) ? null : this.qualityExplanation(key, quality[key]),
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

    totalAdditionPercentage() {
        return this.sumPercentages([...this.additiveRows, ...this.fragranceRows]);
    },

    sumPercentages(rows) {
        return rows.reduce((total, row) => total + this.number(row.percentage), 0);
    },

    oilWeightTotal() {
        return this.oilRows.reduce((total, row) => total + this.rowWeight(row), 0);
    },

    additionWeightTotal() {
        return [...this.additiveRows, ...this.fragranceRows]
            .reduce((total, row) => total + this.rowWeight(row), 0);
    },

    lyeBreakdown() {
        if (this.oilsMissingSap.length > 0) {
            return {
                naoh_theoretical: 0,
                naoh_adjusted: 0,
                koh_theoretical: 0,
                koh_adjusted: 0,
                selected_naoh_weight: 0,
                selected_koh_weight: 0,
                water_weight: this.waterWeightFor(0),
                glycerine_weight: 0,
                koh_to_weigh: 0,
                selected_total_active_lye: 0,
                fatty_acids: {},
            };
        }

        const totals = this.oilRows.reduce((carry, row) => {
            const rowWeight = this.rowWeight(row);
            const kohSap = this.normalizeSapValue(row.koh_sap_value);
            const naohSap = kohSap * 0.713;

            carry.naoh_theoretical += rowWeight * naohSap;
            carry.koh_theoretical += rowWeight * kohSap;

            Object.entries(row.fatty_acid_profile ?? {}).forEach(([key, value]) => {
                carry.fatty_acids[key] = (carry.fatty_acids[key] ?? 0) + (rowWeight * this.number(value));
            });

            return carry;
        }, {
            naoh_theoretical: 0,
            koh_theoretical: 0,
            fatty_acids: {},
        });

        const superfatMultiplier = 1 - (this.number(this.superfat) / 100);
        const naohAdjusted = totals.naoh_theoretical * superfatMultiplier;
        const kohAdjusted = totals.koh_theoretical * superfatMultiplier;
        const kohRatio = this.lyeType === 'dual'
            ? this.number(this.dualKohPercentage) / 100
            : (this.lyeType === 'koh' ? 1 : 0);
        const naohRatio = 1 - kohRatio;
        const selectedNaohWeight = naohAdjusted * naohRatio;
        const selectedKohWeight = kohAdjusted * kohRatio;
        const selectedTotalActiveLye = selectedNaohWeight + selectedKohWeight;
        const waterWeight = this.waterWeightFor(selectedTotalActiveLye);
        const kohToWeigh = selectedKohWeight > 0 && this.kohPurity === 90 ? selectedKohWeight / 0.9 : selectedKohWeight;

        return {
            naoh_theoretical: totals.naoh_theoretical,
            naoh_adjusted: naohAdjusted,
            koh_theoretical: totals.koh_theoretical,
            koh_adjusted: kohAdjusted,
            selected_naoh_weight: selectedNaohWeight,
            selected_koh_weight: selectedKohWeight,
            koh_to_weigh: kohToWeigh,
            selected_total_active_lye: selectedTotalActiveLye,
            water_weight: waterWeight,
            glycerine_weight: (selectedNaohWeight * (92.09382 / 119.9922)) + (selectedKohWeight * (92.09382 / 168.3168)),
            fatty_acids: totals.fatty_acids,
        };
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
        const totals = this.lyeBreakdown().fatty_acids;
        const oilsWeight = this.oilWeightTotal();

        if (oilsWeight <= 0) {
            return {};
        }

        return Object.keys(totals).sort().reduce((profile, key) => {
            profile[key] = totals[key] / oilsWeight;

            return profile;
        }, {});
    },

    finalBatchWeight() {
        const lye = this.lyeBreakdown();
        const lyeToWeigh = this.lyeType === 'naoh'
            ? lye.selected_naoh_weight
            : (this.lyeType === 'koh' ? lye.koh_to_weigh : lye.selected_naoh_weight + lye.koh_to_weigh);

        return this.oilWeightTotal() + this.additionWeightTotal() + lye.water_weight + lyeToWeigh;
    },

    totalFormulaPercentage(row) {
        const totalWeight = this.finalBatchWeight();

        if (totalWeight <= 0) {
            return 0;
        }

        return (this.rowWeight(row) / totalWeight) * 100;
    },

    number(value) {
        const parsed = Number.parseFloat(value);

        return Number.isFinite(parsed) ? parsed : 0;
    },

    normalizeSapValue(value) {
        const sapValue = this.number(value);

        return sapValue > 1 ? sapValue / 1000 : sapValue;
    },

    format(value, decimals = 2) {
        return this.number(value).toFixed(decimals);
    },
});
