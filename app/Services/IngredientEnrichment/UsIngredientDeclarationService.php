<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use Illuminate\Support\Str;

class UsIngredientDeclarationService
{
    /** @var array<string, string> */
    private const FDA_BOTANICAL_LABEL_EXAMPLES = [
        'PRUNUS AMYGDALUS DULCIS OIL' => 'Sweet Almond (Prunus Amygdalus Dulcis) Oil',
    ];

    /** @var list<string> */
    private const FORM_WORDS = ['oil', 'butter', 'tallow', 'fat', 'wax', 'lard', 'suet', 'ghee'];

    /** @var list<string> */
    private const MEANINGFUL_EDITORIAL_SEPARATORS = ['/', '&', '+', '|'];

    /**
     * Editorial grade/processing phrases removed from a catalogue display name
     * before it supplies the common part of an FDA-style label. These describe
     * the product, never the material.
     *
     * @var list<string>
     */
    private const EDITORIAL_PHRASES = [
        'extra virgin',
        'extra-virgin',
        'cold pressed',
        'cold-pressed',
        'expeller pressed',
        'expeller-pressed',
        'cosmetic grade',
        'food grade',
        'pharmaceutical grade',
        'fair trade',
        'fair-trade',
    ];

    /**
     * Single editorial qualifier words removed from a catalogue display name
     * before it supplies the common part of an FDA-style label.
     *
     * @var list<string>
     */
    private const EDITORIAL_QUALIFIERS = [
        'organic',
        'virgin',
        'refined',
        'unrefined',
        'raw',
        'pure',
        'natural',
        'premium',
        'certified',
        'cosmetic',
        'pharmaceutical',
        'culinary',
        'artisan',
        'grade',
        'luxury',
    ];

    /**
     * @param  array{unii?: string|null, common_name?: string|null, inci_names?: list<string>, cas?: list<string>}  candidate
     */
    public function propose(
        array $candidate,
        bool $isColourant = false,
        ?string $verifiedInciName = null,
        ?string $displayName = null,
    ): IngredientSourceStageResult {
        $commonName = trim((string) ($candidate['common_name'] ?? ''));
        if ($commonName === '' || ($isColourant && preg_match('/^CI\s*\d{5}$/i', $commonName) === 1)) {
            $unresolvedMessage = $isColourant
                ? __('ingredient_enrichment.warnings.us_colour_declaration_unresolved')
                : __('ingredient_enrichment.warnings.us_declaration_unresolved');

            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::UsDeclaration,
                status: 'completed',
                data: ['market_code' => 'us', 'declaration_name' => null, 'confidence' => 'unresolved'],
                unresolvedQuestions: [$unresolvedMessage],
            );
        }

        $inciNames = collect([...($candidate['inci_names'] ?? []), $verifiedInciName])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim($name))
            ->unique(fn (string $name): string => Str::lower($name))
            ->values()
            ->all();

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::UsDeclaration,
            status: 'completed',
            data: [
                'market_code' => 'us',
                'declaration_name' => $this->harmonizedBotanicalName($commonName, $inciNames, $displayName),
                'confidence' => 'supported',
            ],
            evidence: [[
                'field' => 'proposal.market_labels.us.declaration_name',
                'source_name' => 'FDA cosmetic ingredient naming guidance',
                'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
                'source_tier' => 'official',
                'confidence' => 'supported',
                'source_version' => '21 CFR 701.3',
                'source_updated_at' => null,
                'retrieved_at' => now()->toImmutable()->toIso8601String(),
            ]],
        );
    }

    /** @param list<string> $inciNames */
    private function harmonizedBotanicalName(string $commonName, array $inciNames, ?string $displayName = null): string
    {
        foreach ($inciNames as $inciName) {
            $officialLabelExample = self::FDA_BOTANICAL_LABEL_EXAMPLES[Str::upper(trim($inciName))] ?? null;

            if ($officialLabelExample !== null) {
                return $officialLabelExample;
            }
        }

        foreach ([$commonName, ...$inciNames] as $name) {
            if (preg_match('/^(?<latin>.+?)\s+\((?<common>[^)]+)\)\s+(?<suffix>.+)$/u', trim($name), $parts) !== 1) {
                continue;
            }

            $plainCommonName = trim($parts['common'].' '.$parts['suffix']);
            if (mb_strtolower($plainCommonName) !== mb_strtolower($commonName)) {
                continue;
            }

            return Str::title($parts['common'])
                .' ('.Str::title($parts['latin']).') '
                .Str::title($parts['suffix']);
        }

        $commonIsLatin = $inciNames !== []
            && mb_strtolower(trim($commonName)) === mb_strtolower(trim((string) $inciNames[0]));
        $commonPart = $commonIsLatin && is_string($displayName) && trim($displayName) !== ''
            ? $this->stripEditorialQualifiers($displayName)
            : $commonName;
        $latin = $commonIsLatin ? $inciNames[0] : ($inciNames[0] ?? null);

        $composed = $latin === null ? null : $this->composeBotanicalLabel($commonPart, $latin);
        if ($composed !== null) {
            return $composed;
        }

        return $commonName;
    }

    /**
     * Removes editorial grade and marketing qualifiers ("Organic Virgin Marula
     * Oil" -> "Marula Oil") so the display name can supply the common part of
     * an FDA-style declaration without leaking product claims onto a
     * regulatory surface. Material-distinguishing adjectives (sweet, bitter,
     * high oleic, ...) are preserved.
     */
    private function stripEditorialQualifiers(string $name): string
    {
        $stripped = trim($name);

        $tokens = $this->editorialTokens($stripped);
        if ($tokens === []) {
            return $stripped;
        }

        $editorialQualifiers = collect(self::EDITORIAL_QUALIFIERS)
            ->map(fn (string $qualifier): string => $this->normalizeEditorialToken($qualifier))
            ->all();

        foreach ($tokens as &$token) {
            $token['remove'] = in_array($token['comparison'], $editorialQualifiers, true);
        }
        unset($token);

        $editorialPhrases = $this->editorialPhraseTokens();
        do {
            $changed = false;
            foreach ($editorialPhrases as $phrase) {
                $changed = $this->markEditorialPhraseMatches($tokens, $phrase) || $changed;
            }
        } while ($changed);

        $stripped = $this->reconstructWithoutEditorialTokens($stripped, $tokens);

        return trim((string) (preg_replace('/\s+/u', ' ', $stripped) ?? $stripped));
    }

    /**
     * @return list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>
     */
    private function editorialTokens(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches, PREG_OFFSET_CAPTURE);

        return collect($matches[0] ?? [])
            ->map(fn (array $match): array => [
                'value' => (string) $match[0],
                'offset' => (int) $match[1],
                'length' => strlen((string) $match[0]),
                'comparison' => $this->normalizeEditorialToken((string) $match[0]),
                'remove' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<list<string>>
     */
    private function editorialPhraseTokens(): array
    {
        $phrases = [];

        foreach (self::EDITORIAL_PHRASES as $phrase) {
            $comparisonPhrase = $this->normalizeUnicodeDashesForComparison($phrase);
            $words = collect(preg_split('/[\s\x{002D}\x{2010}-\x{2015}\x{2212}]+/u', $comparisonPhrase, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->map(fn (string $word): string => $this->normalizeEditorialToken($word))
                ->all();
            if ($words !== []) {
                $phrases[implode('|', $words)] = $words;
            }
        }

        return collect($phrases)->values()->all();
    }

    private function normalizeEditorialToken(string $value): string
    {
        $comparisonValue = $this->trimUnicodePunctuation($this->normalizeUnicodeDashesForComparison($value));

        return mb_strtolower($comparisonValue);
    }

    /**
     * @param  list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>  $tokens
     * @param  list<string>  $phrase
     */
    private function markEditorialPhraseMatches(array &$tokens, array $phrase): bool
    {
        $changed = false;
        $tokenCount = count($tokens);

        foreach (array_keys($tokens) as $start) {
            $matchedIndexes = [];
            $tokenIndex = $start;

            foreach ($phrase as $expected) {
                while ($tokenIndex < $tokenCount
                    && $tokens[$tokenIndex]['remove']
                    && $tokens[$tokenIndex]['comparison'] !== $expected) {
                    $tokenIndex++;
                }

                if ($tokenIndex >= $tokenCount || $tokens[$tokenIndex]['comparison'] !== $expected) {
                    $matchedIndexes = [];
                    break;
                }

                $matchedIndexes[] = $tokenIndex;
                $tokenIndex++;
            }

            if (count($matchedIndexes) !== count($phrase)) {
                continue;
            }

            foreach ($matchedIndexes as $matchedIndex) {
                if ($tokens[$matchedIndex]['remove']) {
                    continue;
                }

                $tokens[$matchedIndex]['remove'] = true;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>  $tokens
     */
    private function reconstructWithoutEditorialTokens(string $value, array $tokens): string
    {
        $result = '';
        $cursor = 0;
        $previousTokenWasRemoved = false;
        $delimiterOffsets = $this->editorialDelimiterOffsets($value, $tokens);
        $preservedSeparatorOffsets = $this->editorialSeparatorOffsets($value, $tokens);
        $preservedPunctuationOffsets = $delimiterOffsets['preserve'] + $preservedSeparatorOffsets;
        $hasRemovedTokens = collect($tokens)->contains(fn (array $token): bool => $token['remove']);

        foreach ($tokens as $token) {
            $separator = substr($value, $cursor, $token['offset'] - $cursor);
            $result .= $this->reconstructSeparator(
                separator: $separator,
                offset: $cursor,
                removeEditorialPunctuation: $token['remove'] || $previousTokenWasRemoved,
                removedDelimiterOffsets: $delimiterOffsets['remove'],
                preservedPunctuationOffsets: $preservedPunctuationOffsets,
            );

            if (! $token['remove']) {
                $result .= $token['value'];
            }

            $cursor = $token['offset'] + $token['length'];
            $previousTokenWasRemoved = $token['remove'];
        }

        $suffix = substr($value, $cursor);
        $result .= $this->reconstructSeparator(
            separator: $suffix,
            offset: $cursor,
            removeEditorialPunctuation: $previousTokenWasRemoved,
            removedDelimiterOffsets: $delimiterOffsets['remove'],
            preservedPunctuationOffsets: $preservedPunctuationOffsets,
        );

        if ($hasRemovedTokens) {
            $result = (string) (preg_replace('/([\(\[\{“‘])\s+/u', '$1', $result) ?? $result);
            $result = (string) (preg_replace('/\s+([)\]}”’])/u', '$1', $result) ?? $result);
        }

        return $result;
    }

    /**
     * @param  array<int, true>  $removedDelimiterOffsets
     * @param  array<int, true>  $preservedPunctuationOffsets
     */
    private function reconstructSeparator(
        string $separator,
        int $offset,
        bool $removeEditorialPunctuation,
        array $removedDelimiterOffsets,
        array $preservedPunctuationOffsets,
    ): string {
        preg_match_all($this->editorialSeparatorPattern(), $separator, $matches, PREG_OFFSET_CAPTURE);
        if (($matches[0] ?? []) === []) {
            return $separator;
        }

        $result = '';
        $cursor = 0;
        foreach ($matches[0] as $match) {
            $punctuation = (string) $match[0];
            $punctuationOffset = (int) $match[1];
            $absoluteOffset = $offset + $punctuationOffset;
            $result .= substr($separator, $cursor, $punctuationOffset - $cursor);

            $isRemovedDelimiter = isset($removedDelimiterOffsets[$absoluteOffset]);
            $isPreservedPunctuation = isset($preservedPunctuationOffsets[$absoluteOffset]);
            if (! $isRemovedDelimiter && (! $removeEditorialPunctuation || $isPreservedPunctuation)) {
                $result .= $punctuation;
            }

            $cursor = $punctuationOffset + strlen($punctuation);
        }

        return $result.substr($separator, $cursor);
    }

    /**
     * @param  list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>  $tokens
     * @return array{remove: array<int, true>, preserve: array<int, true>}
     */
    private function editorialDelimiterOffsets(string $value, array $tokens): array
    {
        $removed = [];
        $preserved = [];

        foreach ($this->editorialWrapperPairs($value) as $pair) {
            $firstTokenIndex = null;
            foreach ($tokens as $tokenIndex => $token) {
                if ($token['offset'] <= $pair['openOffset']
                    || $pair['closeOffset'] < $token['offset'] + $token['length']) {
                    continue;
                }

                $firstTokenIndex = $tokenIndex;
                break;
            }

            $offsets = $firstTokenIndex === null || $tokens[$firstTokenIndex]['remove']
                ? $removed
                : $preserved;
            $offsets[$pair['openOffset']] = true;
            $offsets[$pair['closeOffset']] = true;

            if ($firstTokenIndex === null || $tokens[$firstTokenIndex]['remove']) {
                $removed = $offsets;
            } else {
                $preserved = $offsets;
            }
        }

        return [
            'remove' => $removed,
            'preserve' => $preserved,
        ];
    }

    /**
     * @return list<array{openOffset: int, closeOffset: int}>
     */
    private function editorialWrapperPairs(string $value): array
    {
        $openingToClosing = [
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '"' => '"',
            "'" => "'",
            '“' => '”',
            '‘' => '’',
        ];
        $closingToOpening = array_flip($openingToClosing);
        $stack = [];
        $pairs = [];

        preg_match_all('/[()[\]{}"\'“”‘’]/u', $value, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] ?? [] as $match) {
            $delimiter = (string) $match[0];
            $offset = (int) $match[1];

            if ($delimiter === "'" && $this->isApostropheWithinWord($value, $offset)) {
                continue;
            }

            if (array_key_exists($delimiter, $openingToClosing)) {
                if ($delimiter === '"' || $delimiter === "'") {
                    $topIndex = array_key_last($stack);
                    $top = $topIndex === null ? null : $stack[$topIndex];
                    if (is_array($top) && $top['delimiter'] === $delimiter) {
                        array_pop($stack);
                        $pairs[] = [
                            'openOffset' => $top['offset'],
                            'closeOffset' => $offset,
                        ];

                        continue;
                    }
                }

                $stack[] = [
                    'delimiter' => $delimiter,
                    'offset' => $offset,
                ];

                continue;
            }

            $topIndex = array_key_last($stack);
            $top = $topIndex === null ? null : $stack[$topIndex];
            if (! is_array($top) || $top['delimiter'] !== $closingToOpening[$delimiter]) {
                continue;
            }

            array_pop($stack);
            $pairs[] = [
                'openOffset' => $top['offset'],
                'closeOffset' => $offset,
            ];
        }

        return $pairs;
    }

    private function isApostropheWithinWord(string $value, int $offset): bool
    {
        $before = substr($value, 0, $offset);
        $after = substr($value, $offset + 1);

        return preg_match('/[\p{L}\p{N}]$/u', $before) === 1
            && preg_match('/^[\p{L}\p{N}]/u', $after) === 1;
    }

    /**
     * @param  list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>  $tokens
     * @return array<int, true>
     */
    private function editorialSeparatorOffsets(string $value, array $tokens): array
    {
        $preserved = [];
        $tokenCount = count($tokens);

        foreach (array_keys($tokens) as $start) {
            if (! $tokens[$start]['remove']
                || ($start > 0 && $tokens[$start - 1]['remove'])) {
                continue;
            }

            $end = $start;
            while ($end + 1 < $tokenCount && $tokens[$end + 1]['remove']) {
                $end++;
            }

            if ($start === 0 || $end === $tokenCount - 1 || $this->isFinalFormToken($tokens, $end + 1)) {
                continue;
            }

            $leftSeparatorOffset = $tokens[$start - 1]['offset'] + $tokens[$start - 1]['length'];
            $leftSeparator = substr(
                $value,
                $leftSeparatorOffset,
                $tokens[$start]['offset'] - $leftSeparatorOffset,
            );
            $meaningfulSeparatorOffset = $this->firstMeaningfulSeparatorOffset($leftSeparator);
            if ($meaningfulSeparatorOffset !== null) {
                $preserved[$leftSeparatorOffset + $meaningfulSeparatorOffset] = true;

                continue;
            }

            $rightSeparatorOffset = $tokens[$end]['offset'] + $tokens[$end]['length'];
            $rightSeparator = substr(
                $value,
                $rightSeparatorOffset,
                $tokens[$end + 1]['offset'] - $rightSeparatorOffset,
            );
            $meaningfulSeparatorOffset = $this->firstMeaningfulSeparatorOffset($rightSeparator);
            if ($meaningfulSeparatorOffset !== null) {
                $preserved[$rightSeparatorOffset + $meaningfulSeparatorOffset] = true;
            }
        }

        return $preserved;
    }

    /**
     * @param  list<array{value: string, offset: int, length: int, comparison: string, remove: bool}>  $tokens
     */
    private function isFinalFormToken(array $tokens, int $tokenIndex): bool
    {
        return $tokenIndex === count($tokens) - 1
            && in_array($tokens[$tokenIndex]['comparison'], self::FORM_WORDS, true);
    }

    private function firstMeaningfulSeparatorOffset(string $separator): ?int
    {
        $firstOffset = null;
        foreach (self::MEANINGFUL_EDITORIAL_SEPARATORS as $meaningfulSeparator) {
            $offset = strpos($separator, $meaningfulSeparator);
            if ($offset === false || ($firstOffset !== null && $offset >= $firstOffset)) {
                continue;
            }

            $firstOffset = $offset;
        }

        return $firstOffset;
    }

    private function editorialSeparatorPattern(): string
    {
        $supportedSeparators = implode('', array_map(
            fn (string $separator): string => preg_quote($separator, '/'),
            self::MEANINGFUL_EDITORIAL_SEPARATORS,
        ));

        return '/[\p{P}\x{2212}'.$supportedSeparators.']/u';
    }

    private function normalizeUnicodeDashesForComparison(string $value): string
    {
        return (string) (preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value) ?? $value);
    }

    private function trimUnicodePunctuation(string $value): string
    {
        return trim((string) (preg_replace('/^\p{P}+|\p{P}+$/u', '', $value) ?? $value));
    }

    /**
     * Composes the FDA-style label "Common (Botanical) Form", for example
     * "Coconut (Cocos Nucifera) Oil" or "Beef (Adeps Bovis) Tallow".
     */
    private function composeBotanicalLabel(string $commonName, string $latin): ?string
    {
        $formPattern = implode('|', collect(self::FORM_WORDS)
            ->map(fn (string $form): string => preg_quote($form, '/'))
            ->all());
        $formSeparatorPattern = '(?:\s+|[\x{002D}\x{2010}-\x{2015}\x{2212}])+';
        if (preg_match(
            '/^(?<noun>.+?)'.$formSeparatorPattern.'(?<form>'.$formPattern.')$/iu',
            trim($commonName),
            $parts,
        ) !== 1) {
            return null;
        }

        $noun = trim($parts['noun']);
        $form = trim($parts['form']);
        $latinParts = preg_split('/\s+/u', Str::title($latin), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($latinParts !== []
            && in_array(mb_strtolower((string) end($latinParts)), [...self::FORM_WORDS, 'seed', 'kernel', 'fruit', 'nut', 'extract', 'meal', 'husk'], true)) {
            array_pop($latinParts);
        }
        $latinBase = implode(' ', $latinParts);
        if ($latinBase === '') {
            return null;
        }

        return Str::title($noun).' ('.$latinBase.') '.Str::title($form);
    }
}
