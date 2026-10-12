<?php

namespace App\Support;

class InciName
{
    /**
     * Tokens that stop being identifiers once they are lower-cased.
     *
     * Only ever matched against a token that is already all-caps in the source,
     * so "Tea" in "Black Tea Extract" stays a leaf while "TEA" stays
     * triethanolamine.
     */
    private const IDENTIFIER_TOKENS = [
        'AHA', 'BHA', 'BHT', 'CI', 'DEA', 'DNA', 'EDTA', 'INCI', 'IPM', 'MEA',
        'MSM', 'PABA', 'PEG', 'PG', 'PPG', 'PVP', 'RNA', 'SLES', 'SLS', 'SPF',
        'TEA', 'UV',
    ];

    /**
     * Canonical comparison key.
     *
     * Catalogue dedupe, CosIng and FDA GSRS lookups all compare INCI
     * case-insensitively, so this folds to upper case. It is a key, never a
     * display value — do not render it.
     */
    public static function normalize(?string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    /**
     * Human-facing form for dense views.
     *
     * Stored INCI values are not internally consistent: one catalogue carries
     * all-caps ("THEOBROMA CACAO SEED BUTTER"), title case ("Oenothera Biennis
     * (Evening Primrose) Oil") and already-lower forms ("Adansonia digitata
     * seed oil") side by side, so the column reads as noise even when every
     * individual value is defensible. This folds them onto one sentence-case
     * shape.
     *
     * Two parts receive special handling:
     *
     * - Parenthetical botanical or common names keep their existing mixed case.
     *   All-caps names become title case, so "(SUNFLOWER)" becomes "(Sunflower)"
     *   while "(Evening Primrose)" stays intact.
     * - Identifier tokens. "CI 77007" and "PEG-40 Hydrogenated Castor Oil" stop
     *   being identifiers if they are lower-cased, and the cosmetic chemist
     *   reading them relies on that.
     *
     * Everything else folds to lower case with the first letter raised, which is
     * also the botanical convention for the species epithet and already the
     * dominant shape in the stored data.
     */
    public static function display(?string $value): string
    {
        $value = (string) preg_replace('/\s+/', ' ', trim((string) $value));

        if ($value === '') {
            return '';
        }

        $segments = preg_split('/(\([^)]*\))/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $parts = [];
        $isFirstWord = true;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_starts_with($segment, '(')) {
                $parts[] = self::displayParenthetical($segment);

                continue;
            }

            foreach (explode(' ', trim($segment)) as $token) {
                if ($token === '') {
                    continue;
                }

                $parts[] = self::displayToken($token, $isFirstWord);
                $isFirstWord = false;
            }
        }

        return implode(' ', $parts);
    }

    private static function displayParenthetical(string $value): string
    {
        return (string) preg_replace_callback('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', function (array $matches): string {
            $token = $matches[0];

            if (self::isIdentifierToken($token) || $token !== mb_strtoupper($token)) {
                return $token;
            }

            return mb_convert_case($token, MB_CASE_TITLE);
        }, $value);
    }

    private static function displayToken(string $token, bool $isFirstWord): string
    {
        if (self::isIdentifierToken($token)) {
            return $token;
        }

        $lowered = mb_strtolower($token);

        if (! $isFirstWord) {
            return $lowered;
        }

        return mb_strtoupper(mb_substr($lowered, 0, 1)).mb_substr($lowered, 1);
    }

    /**
     * Whether the token carries identity that lower-casing would destroy.
     *
     * A digit anywhere is treated as identity ("PEG-40", "CI 77007", "R102").
     * All-caps words are only identifiers when they are on the known list, which
     * keeps an all-caps commodity name like "THEOBROMA" foldable while "EDTA"
     * survives.
     */
    private static function isIdentifierToken(string $token): bool
    {
        if (preg_match('/\d/', $token) === 1) {
            return true;
        }

        if ($token !== mb_strtoupper($token) || $token === mb_strtolower($token)) {
            return false;
        }

        return in_array($token, self::IDENTIFIER_TOKENS, true);
    }
}
