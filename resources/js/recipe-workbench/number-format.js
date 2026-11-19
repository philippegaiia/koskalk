const DEFAULT_NUMBER_LOCALE = 'en_US';

function normalizedLocale(locale) {
    return `${locale ?? ''}`.trim().replace('-', '_');
}

function intlLocale(locale) {
    return normalizedLocale(locale).replace('_', '-');
}

function normalizedDecimalText(value) {
    let normalized = `${value ?? ''}`
        .trim()
        .replace(/[\s\u00a0\u202f]/g, '');

    const commaIndex = normalized.lastIndexOf(',');
    const dotIndex = normalized.lastIndexOf('.');

    if (commaIndex >= 0 && dotIndex >= 0) {
        const decimalSeparator = commaIndex > dotIndex ? ',' : '.';
        const groupingSeparator = decimalSeparator === ',' ? '.' : ',';

        normalized = normalized.replaceAll(groupingSeparator, '');
        normalized = normalized.replace(decimalSeparator, '.');
    } else if (commaIndex >= 0) {
        const parts = normalized.split(',');
        const decimalPart = parts.pop();

        normalized = `${parts.join('')}.${decimalPart}`;
    } else if (dotIndex >= 0 && normalized.indexOf('.') !== dotIndex) {
        const parts = normalized.split('.');
        const decimalPart = parts.pop();

        normalized = `${parts.join('')}.${decimalPart}`;
    }

    return normalized;
}

export function parseDecimalInput(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    const normalized = normalizedDecimalText(value);

    if (normalized === '') {
        return 0;
    }

    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Parse input that must be a number and nothing else: "30 ml" or "abc" give
 * null instead of the leading digits.
 */
export function parseStrictDecimalInput(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    const normalized = normalizedDecimalText(value);

    if (!/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(normalized)) {
        return null;
    }

    const parsed = Number(normalized);

    return Number.isFinite(parsed) ? parsed : null;
}

export function formatNumber(value, decimals = 2, locale = DEFAULT_NUMBER_LOCALE) {
    return new Intl.NumberFormat(intlLocale(locale), {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
        useGrouping: false,
    }).format(parseDecimalInput(value));
}

export function formatDecimalInput(value, locale = DEFAULT_NUMBER_LOCALE, maximumFractionDigits = 12) {
    return new Intl.NumberFormat(intlLocale(locale), {
        minimumFractionDigits: 0,
        maximumFractionDigits,
        useGrouping: false,
    }).format(parseDecimalInput(value));
}

export function resolveNumberLocale(preferredLocale, supportedLocales, browserLanguages = [], storedLocale = null) {
    const supported = supportedLocales.map(normalizedLocale);
    const candidates = [preferredLocale, storedLocale, ...browserLanguages]
        .map(normalizedLocale)
        .filter(Boolean);

    for (const candidate of candidates) {
        if (supported.includes(candidate)) {
            return candidate;
        }

        const language = candidate.split('_')[0];
        const languageMatch = supported.find((locale) => locale.split('_')[0] === language);

        if (languageMatch) {
            return languageMatch;
        }
    }

    return supported.includes(DEFAULT_NUMBER_LOCALE)
        ? DEFAULT_NUMBER_LOCALE
        : (supported[0] ?? DEFAULT_NUMBER_LOCALE);
}
