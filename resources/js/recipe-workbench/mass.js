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

export function convertMassPrice(value, fromUnit, toUnit) {
    if (!MASS_UNITS.includes(fromUnit) || !MASS_UNITS.includes(toUnit)) {
        return roundMass(value);
    }

    const price = Number(value);

    if (!Number.isFinite(price) || price < 0) {
        return 0;
    }

    return roundMass((price * GRAMS_PER_UNIT[toUnit]) / GRAMS_PER_UNIT[fromUnit]);
}

export function preferredMassUnit(grams, displaySystem) {
    const quantity = Math.max(0, Number(grams) || 0);

    if (displaySystem === 'us_customary') {
        return quantity >= GRAMS_PER_UNIT.lb ? 'lb' : 'oz';
    }

    return quantity >= GRAMS_PER_UNIT.kg ? 'kg' : 'g';
}

export function massDisplayDecimals(value, unit = 'g') {
    const quantity = Math.abs(Number(value) || 0);

    if (unit === 'g') {
        if (quantity >= 1000) {
            return 1;
        }

        return quantity >= 1 ? 2 : 3;
    }

    if (unit === 'oz') {
        if (quantity >= 10) {
            return 2;
        }

        return quantity >= 1 ? 3 : 4;
    }

    if (unit === 'kg' || unit === 'lb') {
        return quantity >= 1 ? 3 : 4;
    }

    return 2;
}
