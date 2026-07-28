export const MASS_UNITS = Object.freeze(['g', 'kg', 'oz', 'lb']);

const GRAMS_PER_UNIT = Object.freeze({
    g: 1,
    kg: 1000,
    oz: 28.349523125,
    lb: 453.59237,
});

export function roundMass(value) {
    const quantity = Number(value);

    if (!Number.isFinite(quantity)) {
        return 0;
    }

    return Number(quantity.toFixed(9));
}

export function convertMass(value, fromUnit, toUnit) {
    if (!MASS_UNITS.includes(fromUnit) || !MASS_UNITS.includes(toUnit)) {
        return roundMass(value);
    }

    const quantity = Number(value);

    if (!Number.isFinite(quantity) || quantity < 0) {
        return 0;
    }

    return roundMass((quantity * GRAMS_PER_UNIT[fromUnit]) / GRAMS_PER_UNIT[toUnit]);
}

export function preferredMassUnit(grams, displaySystem) {
    const quantity = Math.max(0, Number(grams) || 0);

    if (displaySystem === 'us_customary') {
        return quantity >= GRAMS_PER_UNIT.lb ? 'lb' : 'oz';
    }

    return quantity >= GRAMS_PER_UNIT.kg ? 'kg' : 'g';
}
