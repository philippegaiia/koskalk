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
        $stripped = $this->normalizeUnicodeDashes(trim($name));

        foreach (self::EDITORIAL_PHRASES as $phrase) {
            $stripped = $this->removeEditorialQualifier($stripped, $phrase);
        }

        foreach (self::EDITORIAL_QUALIFIERS as $qualifier) {
            $stripped = $this->removeEditorialQualifier($stripped, $qualifier);
        }

        $stripped = (string) (preg_replace(
            '/(?<![\p{L}\p{N}])[\p{P}]+(?![\p{L}\p{N}])/u',
            ' ',
            $stripped,
        ) ?? $stripped);

        return trim((string) (preg_replace('/\s+/u', ' ', $stripped) ?? $stripped));
    }

    private function removeEditorialQualifier(string $value, string $qualifier): string
    {
        $comparisonQualifier = $this->trimUnicodePunctuation($this->normalizeUnicodeDashes($qualifier));
        $qualifierWords = preg_split('/[\s-]+/u', $comparisonQualifier, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($qualifierWords === []) {
            return $value;
        }

        $pattern = implode('[\s-]+', collect($qualifierWords)
            ->map(fn (string $word): string => preg_quote($word, '/'))
            ->all());

        return (string) (preg_replace(
            '/(?<![\p{L}\p{N}])[\p{P}]*'.$pattern.'[\p{P}]*(?![\p{L}\p{N}])/iu',
            ' ',
            $value,
        ) ?? $value);
    }

    private function normalizeUnicodeDashes(string $value): string
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
        $words = preg_split('/[\s-]+/u', $this->normalizeUnicodeDashes(trim($commonName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 2 || ! in_array(mb_strtolower((string) end($words)), self::FORM_WORDS, true)) {
            return null;
        }

        $noun = implode(' ', array_slice($words, 0, -1));
        $latinParts = preg_split('/[\s-]+/u', $this->normalizeUnicodeDashes(Str::title($latin)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($latinParts !== []
            && in_array(mb_strtolower((string) end($latinParts)), [...self::FORM_WORDS, 'seed', 'kernel', 'fruit', 'nut', 'extract', 'meal', 'husk'], true)) {
            array_pop($latinParts);
        }
        $latinBase = implode(' ', $latinParts);
        if ($latinBase === '') {
            return null;
        }

        return Str::title($noun).' ('.$latinBase.') '.Str::title((string) end($words));
    }
}
