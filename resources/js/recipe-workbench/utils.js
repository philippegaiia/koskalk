import {
    formatNumber as formatLocalizedNumber,
    parseDecimalInput,
    parseStrictDecimalInput,
} from './number-format';

export { parseDecimalInput };

export function clone(value) {
    if (value === null || value === undefined) {
        return value;
    }

    return JSON.parse(JSON.stringify(value));
}

export function number(value) {
    return parseDecimalInput(value);
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

export function format(value, decimals = 2, locale = 'en_US') {
    return formatLocalizedNumber(value, decimals, locale);
}

export function humanizeKey(value) {
    return `${value ?? ''}`
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

export const declaredContentUnits = ['g', 'kg', 'ml', 'l', 'oz', 'lb', 'fl_oz'];

/**
 * A new size gets a random UUID that the server keeps as the size's id, so
 * the key stays the same across saves and a just-saved bench is not dirty.
 * getRandomValues also works on plain-HTTP development hosts, unlike randomUUID.
 */
export function makeProductSizeKey(cryptoSource = globalThis.crypto) {
    const bytes = new Uint8Array(16);
    cryptoSource.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function productSizesFromPayload(rows) {
    return (Array.isArray(rows) ? rows : [])
        .filter((row) => typeof row?.key === 'string' && row.key !== '')
        .map((row) => ({
            key: row.key,
            name: row.name ?? '',
            declared_content_value: declaredContentValue(row.declared_content_value),
            declared_content_unit: declaredContentUnits.includes(row.declared_content_unit) ? row.declared_content_unit : 'g',
        }));
}

export function declaredContentValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = parseStrictDecimalInput(value);

    return parsed === null ? null : roundTo(parsed, 4);
}

/** Declared content is stored with four decimals in a numeric(12,4) column. */
export const declaredContentBounds = { min: 0.0001, max: 99999999.9999 };

export function declaredContentInRange(value) {
    return value === null || (value >= declaredContentBounds.min && value <= declaredContentBounds.max);
}

/**
 * Keep the chosen size while it still exists; otherwise fall back to the
 * first size, or to none when the Product has no sizes.
 */
export function retainedProductSizeKey(sizes, currentKey) {
    return sizes.some((size) => size.key === currentKey)
        ? currentKey
        : (sizes[0]?.key ?? null);
}
