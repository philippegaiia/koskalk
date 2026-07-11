const DEFAULT_NUMBER_LOCALE = 'en_US';

function normalizedLocale(locale) {
    return `${locale ?? ''}`.trim().replace('-', '_');
}

function intlLocale(locale) {
    return normalizedLocale(locale).replace('_', '-');
}

export function parseDecimalInput(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    let normalized = `${value ?? ''}`
        .trim()
        .replace(/[\s\u00a0\u202f]/g, '');

    if (normalized === '') {
        return 0;
    }

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

    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
}

export function formatNumber(value, decimals = 2, locale = DEFAULT_NUMBER_LOCALE) {
    return new Intl.NumberFormat(intlLocale(locale), {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
        useGrouping: false,
    }).format(parseDecimalInput(value));
}

export function formatDecimalInput(value, locale = DEFAULT_NUMBER_LOCALE) {
    return new Intl.NumberFormat(intlLocale(locale), {
        minimumFractionDigits: 0,
        maximumFractionDigits: 12,
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
