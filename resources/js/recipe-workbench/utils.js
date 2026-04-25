export function parseDecimalInput(value) {
    const normalized = `${value ?? ''}`.replace(',', '.');

    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
}

export function clone(value) {
    if (value === null || value === undefined) {
        return value;
    }

    return JSON.parse(JSON.stringify(value));
}

export function number(value) {
    const parsed = Number.parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

export function nonNegativeNumber(value) {
    return Math.max(0, number(value));
}

export function clampPercentage(value) {
    return Math.min(100, nonNegativeNumber(value));
}

export function roundTo(value, decimals = 3) {
    const factor = 10 ** decimals;

    return Math.round(number(value) * factor) / factor;
}

export function format(value, decimals = 2) {
    return number(value).toFixed(decimals);
}

export function humanizeKey(value) {
    return `${value ?? ''}`
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
