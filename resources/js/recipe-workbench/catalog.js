import { format, humanizeKey, number } from './utils';

export const CATEGORY_OPTIONS = [
    { value: 'all', label: 'All' },
    { value: 'carrier_oil', label: 'Carrier Oils' },
    { value: 'essential_oil', label: 'Essential Oils' },
    { value: 'fragrance_oil', label: 'Fragrance Oils' },
    { value: 'botanical_extract', label: 'Botanical Extracts' },
    { value: 'co2_extract', label: 'CO2 Extracts' },
    { value: 'liquid', label: 'Liquids' },
    { value: 'glycol', label: 'Glycols' },
    { value: 'clay', label: 'Clays' },
    { value: 'colorant', label: 'Colorants' },
    { value: 'preservative', label: 'Preservatives' },
    { value: 'additive', label: 'Additives' },
];

const INGREDIENT_CATEGORY_CODES = {
    carrier_oil: 'CA',
    essential_oil: 'EO',
    fragrance_oil: 'FO',
    botanical_extract: 'BE',
    co2_extract: 'CO2',
    liquid: 'LI',
    glycol: 'GL',
    clay: 'CL',
    colorant: 'CL',
    preservative: 'PR',
    additive: 'AD',
};

const FATTY_ACID_LABELS = {
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

export function filterIngredients(ingredients, search, activeCategory) {
    const normalizedSearch = search.trim().toLowerCase();

    return ingredients.filter((ingredient) => {
        const matchesCategory = activeCategory === 'all' || ingredient.category === activeCategory;
        const matchesSearch = normalizedSearch === ''
            || ingredient.name.toLowerCase().includes(normalizedSearch)
            || (ingredient.inci_name ?? '').toLowerCase().includes(normalizedSearch);

        return matchesCategory && matchesSearch;
    });
}

export function normalizedIfraProductCategoryId(selectedIfraProductCategoryId) {
    const candidate = number(selectedIfraProductCategoryId);

    return candidate > 0 ? candidate : null;
}

export function selectedIfraProductCategory(ifraProductCategories, selectedIfraProductCategoryId) {
    const selectedId = normalizedIfraProductCategoryId(selectedIfraProductCategoryId);

    if (selectedId === null) {
        return null;
    }

    return ifraProductCategories.find((category) => number(category.id) === selectedId) ?? null;
}

export function ingredientMonogram(ingredient) {
    const name = `${ingredient?.name ?? ''}`.trim();

    if (name === '') {
        return 'IN';
    }

    const words = name.split(/\s+/).filter(Boolean);

    if (words.length >= 2) {
        return `${words[0][0] ?? ''}${words[1][0] ?? ''}`.toUpperCase();
    }

    const compact = name.replace(/[^a-z0-9]/gi, '');

    return (compact.slice(0, 2) || 'IN').toUpperCase();
}

export function ingredientCategoryCode(ingredient) {
    return INGREDIENT_CATEGORY_CODES[ingredient?.category] ?? ingredientMonogram(ingredient);
}

export function ingredientInspectorRows(ingredient) {
    const rows = [];

    if (number(ingredient?.koh_sap_value) > 0) {
        rows.push({
            label: 'KOH SAP',
            value: format(ingredient.koh_sap_value, 3),
        });
    }

    if (number(ingredient?.naoh_sap_value) > 0) {
        rows.push({
            label: 'NaOH SAP',
            value: format(ingredient.naoh_sap_value, 3),
        });
    }

    return rows;
}

export function ingredientFattyAcidRows(ingredient) {
    return Object.entries(ingredient?.fatty_acid_profile ?? {})
        .map(([key, value]) => ({
            key,
            label: fattyAcidLabels()[key] ?? humanizeKey(key),
            value: number(value),
        }))
        .filter((row) => row.value > 0)
        .sort((left, right) => right.value - left.value);
}

export function fattyAcidLabels() {
    return FATTY_ACID_LABELS;
}

export function targetPhaseForCategory(category) {
    if (['essential_oil', 'botanical_extract', 'co2_extract', 'fragrance_oil'].includes(category)) {
        return 'fragrance';
    }

    return category === 'carrier_oil' ? 'saponified_oils' : 'additives';
}

export function resolveTargetPhase(ingredient, requestedPhase = null) {
    const availablePhases = Array.isArray(ingredient.available_phases) ? ingredient.available_phases : [];

    if (requestedPhase && availablePhases.includes(requestedPhase)) {
        return requestedPhase;
    }

    if (ingredient.default_phase && availablePhases.includes(ingredient.default_phase)) {
        return ingredient.default_phase;
    }

    if (availablePhases.length > 0) {
        return availablePhases[0];
    }

    return targetPhaseForCategory(ingredient.category);
}
