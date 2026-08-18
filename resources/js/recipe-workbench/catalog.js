import { format, humanizeKey, number } from './utils';

const INGREDIENT_CATEGORY_CODES = {
    lipids: 'LI',
    waxes: 'WA',
    hydrocarbons: 'HC',
    silicones: 'SI',
    fatty_derivatives: 'FD',
    surfactants: 'SU',
    emulsifiers: 'EM',
    humectants_polyols: 'HP',
    water_solvents_carriers: 'WS',
    rheology_modifiers: 'RM',
    functional_polymers: 'FP',
    minerals_salts_powders: 'MS',
    actives: 'AC',
    botanicals_extracts: 'BE',
    aromatic_materials: 'AR',
    colourants: 'CO',
    preservation_stability: 'PS',
    ph_adjusters_buffers: 'PH',
    soapmaking_alkalis: 'SA',
    exfoliants_abrasives: 'EA',
    bases_blends_premixes: 'BB',
    other: 'OT',
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

export function categoryOptions(ingredients, allLabel = 'All') {
    const categories = new Map();

    ingredients.forEach((ingredient) => {
        if (!ingredient.category || categories.has(ingredient.category)) {
            return;
        }

        categories.set(
            ingredient.category,
            ingredient.category_label || humanizeKey(ingredient.category),
        );
    });

    const representedCategories = Array.from(categories, ([value, label]) => ({ value, label }))
        .sort((left, right) => left.label.localeCompare(right.label));

    return [{ value: 'all', label: allLabel }, ...representedCategories];
}

export function filterIngredients(ingredients, search, activeCategory) {
    const normalizedSearch = search.trim().toLowerCase();

    return ingredients.filter((ingredient) => {
        const matchesCategory = activeCategory === 'all' || ingredient.category === activeCategory;
        const identifiers = (ingredient.identifiers ?? [])
            .map((identifier) => `${identifier.scheme ?? ''} ${identifier.value ?? ''}`)
            .join(' ')
            .toLowerCase();
        const aliases = (ingredient.aliases ?? []).join(' ').toLowerCase();
        const taxonomy = [
            ingredient.category,
            ingredient.category_label,
            ingredient.subcategory,
            ingredient.subcategory_label,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        const matchesSearch = normalizedSearch === ''
            || ingredient.name.toLowerCase().includes(normalizedSearch)
            || (ingredient.inci_name ?? '').toLowerCase().includes(normalizedSearch)
            || identifiers.includes(normalizedSearch)
            || aliases.includes(normalizedSearch)
            || taxonomy.includes(normalizedSearch);

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
    if (['aromatic_materials', 'botanicals_extracts'].includes(category)) {
        return 'fragrance';
    }

    return category === 'lipids' ? 'saponified_oils' : 'additives';
}

export function resolveTargetPhase(ingredient, requestedPhase = null) {
    const availablePhases = Array.isArray(ingredient.available_phases) ? ingredient.available_phases : [];

    if (requestedPhase === 'lye_water') {
        return requestedPhase;
    }

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
