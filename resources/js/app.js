import './bootstrap';

window.recipeWorkbench = (payload) => ({
    formulaName: 'New Soap Formula',
    oilUnit: 'g',
    oilWeight: 1000,
    editMode: 'percentage',
    lyeType: 'naoh',
    kohPurity: 90,
    waterMode: 'percent_of_oils',
    waterValue: 38,
    superfat: 5,
    search: '',
    activeCategory: 'carrier_oil',
    ifraProductCategories: payload.ifraProductCategories ?? [],
    selectedIfraProductCategoryId: payload.ifraProductCategories[0]?.id ?? null,
    ingredients: payload.ingredients ?? [],
    phaseOrder: payload.phases ?? [],
    phaseItems: {
        saponified_oils: [],
        additives: [],
        fragrance: [],
    },

    init() {
        if (this.filteredIngredients.length > 0 && this.phaseItems.saponified_oils.length === 0) {
            this.addIngredient(this.filteredIngredients[0]);
        }
    },

    get categoryOptions() {
        return [
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
            const matchesCategory = ingredient.category === this.activeCategory;
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

    totalOilPercentage() {
        return this.sumPercentages(this.oilRows);
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
                water_weight: this.waterWeightFor(0),
                glycerine_weight: 0,
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
        const selectedLye = this.lyeType === 'koh' ? kohAdjusted : naohAdjusted;
        const waterWeight = this.waterWeightFor(selectedLye);
        const kohToWeigh = this.kohPurity === 90 ? kohAdjusted / 0.9 : kohAdjusted;

        return {
            naoh_theoretical: totals.naoh_theoretical,
            naoh_adjusted: naohAdjusted,
            koh_theoretical: totals.koh_theoretical,
            koh_adjusted: kohAdjusted,
            koh_to_weigh: kohToWeigh,
            water_weight: waterWeight,
            glycerine_weight: kohAdjusted * (92.09382 / 168.3168),
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

    qualityMetrics() {
        const profile = this.averageFattyAcidProfile();
        const lauric = profile.lauric ?? 0;
        const myristic = profile.myristic ?? 0;
        const palmitic = profile.palmitic ?? 0;
        const stearic = profile.stearic ?? 0;
        const ricinoleic = profile.ricinoleic ?? 0;
        const oleic = profile.oleic ?? 0;
        const linoleic = profile.linoleic ?? 0;
        const linolenic = profile.linolenic ?? 0;
        const iodine = (ricinoleic * 0.901) + (oleic * 0.86) + (linoleic * 1.732) + (linolenic * 2.616);
        const kohTheoretical = this.lyeBreakdown().koh_theoretical;
        const oilsWeight = this.oilWeightTotal();
        const ins = oilsWeight <= 0 ? 0 : ((kohTheoretical / oilsWeight) * 1000) - iodine;

        return {
            hardness: lauric + myristic + palmitic + stearic,
            cleansing: lauric + myristic,
            conditioning: oleic + ricinoleic + linoleic + linolenic,
            bubbly: lauric + myristic + ricinoleic,
            creamy: palmitic + stearic + ricinoleic,
            iodine,
            ins,
        };
    },

    finalBatchWeight() {
        const lye = this.lyeBreakdown();

        return this.oilWeightTotal() + this.additionWeightTotal() + lye.water_weight + (this.lyeType === 'koh' ? lye.koh_adjusted : lye.naoh_adjusted);
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
