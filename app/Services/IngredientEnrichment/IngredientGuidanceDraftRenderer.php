<?php

namespace App\Services\IngredientEnrichment;

use Illuminate\Support\Arr;
use RuntimeException;

class IngredientGuidanceDraftRenderer
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $context
     * @return array{info_markdown: string, warnings: list<string>, unresolved_questions: list<string>}
     */
    public function render(array $draft, array $context): array
    {
        $this->validateDraftShape($draft);

        $sections = [
            'overview' => $this->heading('overview'),
            'formulation_use' => $this->heading('formulation_use'),
            'soapmaking' => (string) config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking'),
        ];
        $sectionTexts = [];
        $acceptedUsageClaims = [];
        $skippedClaims = 0;
        $renderedClaims = 0;

        foreach ($sections as $section => $heading) {
            $claims = $draft[$section];
            if (! is_array($claims)) {
                $this->invalid();
            }

            if ($section === 'soapmaking' && $claims === []) {
                $sectionTexts[$section] = [];

                continue;
            }

            $texts = [];
            foreach ($claims as $index => $claim) {
                if (! is_array($claim)) {
                    $this->invalid();
                }

                $text = $this->validateClaim($claim, $section, $index, $context);
                if ($text === null) {
                    $skippedClaims++;

                    continue;
                }

                $texts[] = $text;
                $renderedClaims++;
                if (($claim['claim_type'] ?? null) === 'usage'
                    && ($claim['support_type'] ?? null) === 'evidence') {
                    $acceptedUsageClaims[$section][] = $claim;
                }
            }

            $sectionTexts[$section] = $texts;
        }

        foreach ($this->baselineUseLevelSentences($context, $sections, $skippedClaims) as $baseline) {
            $section = $baseline['section'];
            $sentence = $baseline['text'];
            if (($sectionTexts[$section] ?? []) === []
                || ! $this->containsRenderedSentence($sectionTexts[$section], $sentence)) {
                if ($this->isContradictedBaselineUseLevel(
                    $sentence,
                    $section,
                    $acceptedUsageClaims[$section] ?? [],
                    $context,
                )) {
                    continue;
                }

                $sectionTexts[$section][] = $sentence;
                $renderedClaims++;
            }
        }

        $blocks = [];
        foreach ($sections as $section => $heading) {
            $texts = $sectionTexts[$section] ?? [];
            if ($section === 'soapmaking' && $texts === []) {
                continue;
            }

            $blocks[] = '## '.$heading.($texts === [] ? '' : "\n\n".implode(' ', $texts));
        }

        $markdown = implode("\n\n", $blocks);
        $maximumWords = (int) config('ingredient-enrichment.guidance.maximum_words', 160);
        if ($maximumWords > 0 && $this->wordCount($markdown) > $maximumWords) {
            $this->invalid();
        }
        $maximumCharacters = (int) config('ingredient-enrichment.guidance.maximum_characters', 2000);
        if ($maximumCharacters > 0 && $this->visibleCharacterCount($markdown) > $maximumCharacters) {
            $this->invalid();
        }

        $warnings = $this->stringList($draft['warnings']);
        if ($skippedClaims > 0) {
            $warnings[] = (string) __('ingredient_enrichment.warnings.guidance_claim_omitted');
        }

        if ($renderedClaims === 0) {
            return [
                'info_markdown' => '',
                'warnings' => array_values(array_unique($warnings)),
                'unresolved_questions' => $this->stringList($draft['unresolved_questions']),
            ];
        }

        return [
            'info_markdown' => $markdown,
            'warnings' => $warnings,
            'unresolved_questions' => $this->stringList($draft['unresolved_questions']),
        ];
    }

    /** @param array<string, mixed> $draft */
    private function validateDraftShape(array $draft): void
    {
        $expected = ['overview', 'formulation_use', 'soapmaking', 'warnings', 'unresolved_questions'];
        if (array_diff($expected, array_keys($draft)) !== []
            || array_diff(array_keys($draft), $expected) !== []) {
            $this->invalid();
        }

        foreach (['warnings', 'unresolved_questions'] as $field) {
            if (! is_array($draft[$field])
                || collect($draft[$field])->contains(fn (mixed $value): bool => ! is_string($value))) {
                $this->invalid();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $context
     */
    private function validateClaim(array $claim, string $section, int|string $index, array $context): ?string
    {
        $expected = ['text', 'claim_type', 'support_type', 'evidence_indexes', 'fact_paths', 'usage_application'];
        if (array_diff($expected, array_keys($claim)) !== []
            || array_diff(array_keys($claim), $expected) !== []) {
            $this->invalid();
        }

        $text = is_string($claim['text'] ?? null) ? trim($claim['text']) : '';
        $claimType = $claim['claim_type'] ?? null;
        $supportType = $claim['support_type'] ?? null;
        $usageApplication = $claim['usage_application'] ?? null;
        $evidenceIndexes = $claim['evidence_indexes'];
        $factPaths = $claim['fact_paths'];

        if ($text === ''
            || preg_match('/[\r\n]/u', $text) === 1
            || str_contains($text, '##')
            || preg_match_all('/[.!?](?=\s|$)/u', $text) > 1
            || $this->isCatalogueMetaClaim($text)
            || $this->isEvidenceMetaClaim($text)
            || ! is_string($claimType)
            || ! in_array($claimType, config('ingredient-enrichment.openai.guidance_research.allowed_claim_types', []), true)
            || ! is_string($supportType)
            || ! in_array($supportType, ['evidence', 'fact'], true)
            || ! is_string($usageApplication)
            || ! in_array($usageApplication, config('ingredient-enrichment.openai.guidance_research.allowed_usage_applications', []), true)
            || ! is_array($evidenceIndexes)
            || ! is_array($factPaths)) {
            return null;
        }

        if ($claimType === 'usage') {
            if (($section === 'formulation_use' && $usageApplication !== 'cosmetics')
                || ($section === 'soapmaking' && $usageApplication !== 'soapmaking')
                || ! in_array($section, ['formulation_use', 'soapmaking'], true)) {
                return null;
            }
        } elseif ($usageApplication !== 'not_applicable') {
            return null;
        }

        if ($section === 'formulation_use'
            && $supportType !== 'evidence'
            && ! ($supportType === 'fact' && $factPaths === ['current.canonical.info_markdown'])) {
            return null;
        }

        if ($section === 'soapmaking' && $claimType !== 'soapmaking' && $claimType !== 'usage') {
            return null;
        }

        if ($supportType === 'evidence') {
            if (! $this->validateEvidenceSupport($claim, $context)) {
                return null;
            }
        } elseif (! $this->validateFactSupport($claim, $section, $context)) {
            return null;
        }

        if ($section === 'soapmaking'
            && $supportType === 'fact'
            && $claimType !== 'soapmaking'
            && ! ($claimType === 'usage' && $factPaths === ['current.canonical.info_markdown'])) {
            return null;
        }

        return $text;
    }

    /** @param array<string, mixed> $claim @param array<string, mixed> $context */
    private function validateEvidenceSupport(array $claim, array $context): bool
    {
        if ($claim['fact_paths'] !== [] || $claim['evidence_indexes'] === []) {
            return false;
        }

        $evidence = is_array($context['guidance_evidence'] ?? null) ? $context['guidance_evidence'] : [];
        foreach ($claim['evidence_indexes'] as $index) {
            if (! is_int($index) || ! array_key_exists($index, $evidence) || ! is_array($evidence[$index])) {
                return false;
            }

            $row = $evidence[$index];
            if (($row['claim_type'] ?? null) !== $claim['claim_type']) {
                return false;
            }

            if ($this->leaksEvidenceCode((string) ($claim['text'] ?? ''), $row)) {
                return false;
            }

            if (! $this->validateEvidenceBoundaries($claim, $row, $context)) {
                return false;
            }

            if ($claim['claim_type'] === 'usage') {
                if (($row['evidence_kind'] ?? null) !== 'formulation_recommendation'
                    || ($row['usage_application'] ?? null) !== $claim['usage_application']
                    || ($row['recommended_min_percent'] ?? null) === null && ($row['recommended_max_percent'] ?? null) === null
                    || ($row['percentage_basis'] ?? 'not_applicable') === 'not_applicable') {
                    return false;
                }

                if (count($claim['evidence_indexes']) !== 1) {
                    return false;
                }

                if (! $this->validateUsageClaimText($claim, $row)) {
                    return false;
                }
            } elseif (($row['usage_application'] ?? 'not_applicable') !== 'not_applicable') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $evidence */
    private function leaksEvidenceCode(string $text, array $evidence): bool
    {
        $sourceName = (string) ($evidence['source_name'] ?? '');
        preg_match_all('/\b[A-Z]{2,}[0-9][A-Z0-9-]*\b/u', $sourceName, $matches);

        $lowerText = mb_strtolower($text);
        foreach ($matches[0] as $token) {
            if (str_contains($lowerText, mb_strtolower($token))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $claim @param array<string, mixed> $evidence */
    private function validateUsageClaimText(array $claim, array $evidence): bool
    {
        $text = mb_strtolower((string) $claim['text']);
        $basisPattern = match ($evidence['percentage_basis'] ?? null) {
            'total_formula' => '/\btotal\s+formula\b/u',
            'oil_phase' => '/\boil\s+phase\b/u',
            'soap_oils' => '/\b(?:soap[-\s]?oil(?:s)?|oil)\s+blend\b/u',
            default => null,
        };
        if ($basisPattern === null || preg_match($basisPattern, $text) !== 1) {
            return false;
        }

        $minimum = $evidence['recommended_min_percent'] ?? null;
        $maximum = $evidence['recommended_max_percent'] ?? null;
        if (is_string($minimum) && is_string($maximum)) {
            $rangePattern = '/\b'.$this->percentagePattern($minimum)
                .'\s*(?:%\s*)?(?:-|–|—|to)\s*'
                .$this->percentagePattern($maximum).'\s*(?:%|percent)(?![\p{L}\p{N}])/u';
            if (preg_match($rangePattern, $text) !== 1) {
                return false;
            }

            return true;
        }

        if (is_string($minimum)) {
            $minimumPattern = '/\b(?:at\s+least|minimum(?:\s+of)?|from)\s+'
                .$this->percentagePattern($minimum).'\s*(?:%|percent)(?![\p{L}\p{N}])/u';
            if (preg_match($minimumPattern, $text) !== 1) {
                return false;
            }

            return true;
        }

        if (! is_string($maximum)) {
            return false;
        }

        $maximumPattern = '/\b(?:up\s+to|at\s+most|maximum(?:\s+of)?)\s+'
            .$this->percentagePattern($maximum).'\s*(?:%|percent)(?![\p{L}\p{N}])/u';
        if (preg_match($maximumPattern, $text) !== 1) {
            return false;
        }

        return true;
    }

    private function percentagePattern(string $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        return preg_quote($integer, '/').($fraction === ''
            ? '(?:\\.0+)?'
            : '\\.'.preg_quote($fraction, '/').'0*');
    }

    /**
     * Keep evidence-linked claims bounded to what the source can support.
     *
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $context
     */
    private function validateEvidenceBoundaries(array $claim, array $evidence, array $context): bool
    {
        $text = mb_strtolower((string) ($claim['text'] ?? ''));
        $claimType = (string) ($claim['claim_type'] ?? '');

        if ($claimType !== 'solubility' && $this->isGenericWaterSolubilityClaim($text)) {
            return false;
        }

        if (($evidence['evidence_kind'] ?? null) === 'experimental_observation'
            && ! $this->hasBoundedEvidenceQualifier($text)) {
            return false;
        }

        if ($this->isUniversalEmulsifierClaim($text)) {
            return false;
        }

        return true;
    }

    private function isGenericWaterSolubilityClaim(string $text): bool
    {
        return preg_match('/\b(?:not\s+soluble|insoluble|immiscible)\b.{0,60}\bwater\b/u', $text) === 1
            || preg_match('/\b(?:not\s+water[-\s]?soluble|water[-\s]?insoluble|water[-\s]?immiscible)\b/u', $text) === 1
            || preg_match('/\bwater\b.{0,60}\b(?:not\s+soluble|insoluble|immiscible)\b/u', $text) === 1;
    }

    private function isUniversalEmulsifierClaim(string $text): bool
    {
        return preg_match('/\b(?:universal|always|never)\b.{0,80}\b(?:emulsif\w*|emulsion|dispersion)\b/u', $text) === 1
            || preg_match('/\b(?:emulsif\w*|emulsion|dispersion)\b.{0,80}\b(?:universal|always|never)\b/u', $text) === 1
            || preg_match('/\b(?:requires?|needs?|must\s+use)\b.{0,60}\b(?:an?\s+)?(?:universal\s+)?emulsif\w*\b/u', $text) === 1;
    }

    private function hasBoundedEvidenceQualifier(string $text): bool
    {
        return preg_match('/\b(?:reported|observed|experiment(?:al)?|study|tested|under\b|product[-\s]?grade|specific\s+grade|cited)\b/u', $text) === 1;
    }

    private function isCatalogueMetaClaim(string $text): bool
    {
        $lower = mb_strtolower($text);

        return preg_match('/\b(?:classified|categorized)\s+as\b/u', $lower) === 1
            || preg_match('/\bwithin\s+the\s+[\w\s-]{1,24}\s+category\b/u', $lower) === 1;
    }

    private function isEvidenceMetaClaim(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/\bthis[-\s]+(?:(?:particular|specific)[-\s]+)?(?:product[-\s]+)?grade\b/u', $lower) === 1) {
            return true;
        }

        $evidenceAdjectives = '(?:cited|documented|specified|referenced|supplied|reported|listed|verified)';
        $evidenceSubject = '(?:materials?|(?:product[-\s]+)?grades?|profiles?|data)';

        if (preg_match('/\b'.$evidenceAdjectives.'[-\s,;:]+(?:[\p{L}\p{N}][\p{L}\p{N}-]*[-\s,;:]+){0,3}'.$evidenceSubject.'\b/u', $lower) === 1
            || preg_match('/\b'.$evidenceSubject.'\b[^.!?]{0,24}\b(?:is|was|are|were)\s+(?:not\s+)?'.$evidenceAdjectives.'\b/u', $lower) === 1) {
            return true;
        }

        $sourceNarrationVerbs = '(?:'.implode('|', [
            'recommend',
            'recommends',
            'recommended',
            'recommending',
            'describe',
            'describes',
            'described',
            'describing',
            'report',
            'reports',
            'reported',
            'reporting',
            'list',
            'lists',
            'listed',
            'listing',
            'specify',
            'specifies',
            'specified',
            'specifying',
            'state',
            'states',
            'stated',
            'stating',
            'note',
            'notes',
            'noted',
            'noting',
            'advise',
            'advises',
            'advised',
            'advising',
            'say',
            'says',
            'said',
            'saying',
            'suggest',
            'suggests',
            'suggested',
            'suggesting',
            'indicate',
            'indicates',
            'indicated',
            'indicating',
        ]).')';
        $sourceSubject = '(?:supplier|manufacturer)s?';
        $sourceArticle = '(?:a|the|one|some|multiple)?\s*';
        $sourceSeparator = '[-\s,:;–—]+';
        $sourceQualifierWord = '[\p{L}\p{M}\p{N}&.\'’\-]+';
        $qualifiedSourceSubject = '(?:supplier|manufacturer|vendor|brand|company|producer)s?'
            .$sourceSeparator.$sourceQualifierWord
            .'(?:'.$sourceSeparator.$sourceQualifierWord.'){0,3}';
        $sourceAdverb = '(?:[\p{L}\p{M}]{1,24}ly|also|often|still|indeed)';
        $sourceDocument = '(?:(?:technical|product|material)[-\s]+)?(?:data[-\s]+sheet|datasheet|sheet|document|specification)s?';

        $namedSourceWord = '(?:[\p{Lu}][\p{L}\p{M}\p{N}&.\'’\-]*|[A-Z]{2,}[A-Z0-9&.\'’\-]*)';
        $namedSource = '(?:'.$namedSourceWord.'(?:\s+'.$namedSourceWord.'){0,3})';
        $namedSourceAdverbs = '(?:\s+'.$sourceAdverb.'){0,2}';

        return preg_match('/\b'.$sourceArticle.$sourceSubject.'(?:'.$sourceSeparator.$sourceAdverb.'){0,2}'.$sourceSeparator.$sourceNarrationVerbs.'\b/u', $lower) === 1
            || preg_match('/\b'.$sourceArticle.$qualifiedSourceSubject.$sourceSeparator.$sourceNarrationVerbs.'\b/u', $lower) === 1
            || preg_match('/\b'.$sourceArticle.$sourceSubject."['’]s?\s+".$sourceDocument.'\s+'.$sourceNarrationVerbs.'\b/u', $lower) === 1
            || preg_match('/\b'.$sourceNarrationVerbs.'\b[^.!?]{0,80}\bby\s+'.$sourceArticle.$sourceSubject.'(?![-\p{L}\p{N}_])/u', $lower) === 1
            || preg_match('/\baccording\s+to\s+'.$sourceArticle.$sourceSubject.'(?![-\p{L}\p{N}_])/u', $lower) === 1
            || preg_match('/\b'.$namedSource.$namedSourceAdverbs.'\s+'.$sourceNarrationVerbs.'\b/u', $text) === 1
            || preg_match('/\b'.$namedSource."['’]s?\s+".$sourceDocument.'\s+'.$sourceNarrationVerbs.'\b/u', $text) === 1
            || preg_match('/\b'.$sourceNarrationVerbs.'\b[^.!?]{0,80}\bby\s+(?:the\s+)?'.$namedSource.'(?![-\p{L}\p{N}_])/u', $text) === 1
            || preg_match('/\baccording\s+to\s+(?:the\s+)?'.$namedSource.'(?![-\p{L}\p{N}_])/u', $text) === 1;
    }

    /**
     * @param  array<string, string>  $sections
     * @return list<array{section:string,text:string}>
     */
    private function baselineUseLevelSentences(array $context, array $sections, int &$skippedClaims): array
    {
        $baseline = Arr::get($context, 'current.canonical.info_markdown');
        if (! is_string($baseline) || trim($baseline) === '') {
            return [];
        }

        $sectionByHeading = collect($sections)
            ->mapWithKeys(fn (string $heading, string $section): array => [mb_strtolower(trim($heading)) => $section])
            ->all();
        $sectionLines = [];
        $currentSection = null;
        foreach (preg_split('/\R/u', $baseline) ?: [] as $line) {
            if (preg_match('/^##\s+(.+)$/u', trim($line), $matches) === 1) {
                $currentSection = $sectionByHeading[mb_strtolower(trim($matches[1]))] ?? null;

                continue;
            }

            if (is_string($currentSection)) {
                $sectionLines[$currentSection][] = $line;
            }
        }

        $sentences = [];
        foreach ($sectionLines as $section => $lines) {
            $content = trim(implode("\n", $lines));
            foreach (preg_split('/(?<=[.!?])\s+/u', $content) ?: [] as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === ''
                    || preg_match('/\btypical\s+use\s+level\b/iu', $sentence) !== 1
                    || preg_match('/\b\d+(?:\.\d+)?\s*%/u', $sentence) !== 1) {
                    continue;
                }

                if ($this->isEvidenceMetaClaim($sentence)) {
                    $skippedClaims++;

                    continue;
                }

                $sentences[] = ['section' => $section, 'text' => $sentence];
            }
        }

        return $sentences;
    }

    /** @param list<string> $texts */
    private function containsRenderedSentence(array $texts, string $sentence): bool
    {
        foreach ($texts as $text) {
            if (str_contains($text, $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $acceptedUsageClaims
     * @param  array<string, mixed>  $context
     */
    private function isContradictedBaselineUseLevel(
        string $sentence,
        string $section,
        array $acceptedUsageClaims,
        array $context,
    ): bool {
        $baselineBounds = $this->usageBounds($sentence);
        if ($baselineBounds === null) {
            return false;
        }

        $application = $section === 'soapmaking' ? 'soapmaking' : 'cosmetics';
        foreach ($acceptedUsageClaims as $claim) {
            if (($claim['usage_application'] ?? null) !== $application) {
                continue;
            }

            $claimBounds = $this->usageBounds((string) ($claim['text'] ?? ''));
            if ($claimBounds !== null && $claimBounds !== $baselineBounds) {
                return true;
            }
        }

        foreach (is_array($context['guidance_evidence'] ?? null) ? $context['guidance_evidence'] : [] as $evidence) {
            if (! is_array($evidence)
                || ($evidence['claim_type'] ?? null) !== 'usage'
                || ($evidence['usage_application'] ?? null) !== $application) {
                continue;
            }

            $evidenceBounds = $this->usageBoundsFromEvidence($evidence);
            if ($evidenceBounds !== null && $evidenceBounds !== $baselineBounds) {
                return true;
            }
        }

        return false;
    }

    /** @return array{minimum:?string,maximum:?string,basis:?string}|null */
    private function usageBounds(string $text): ?array
    {
        $text = mb_strtolower($text);
        $basis = match (true) {
            preg_match('/\btotal\s+formula\b/u', $text) === 1 => 'total_formula',
            preg_match('/\boil\s+phase\b/u', $text) === 1 => 'oil_phase',
            preg_match('/\b(?:soap[-\s]?oil(?:s)?|oil)\s+blend\b/u', $text) === 1 => 'soap_oils',
            default => null,
        };
        $number = '\\d+(?:\\.\\d+)?';
        if (preg_match('/\\b(?<minimum>'.$number.')\\s*%?\\s*(?:-|–|—|to)\\s*(?<maximum>'.$number.')\\s*%/u', $text, $matches) === 1) {
            return [
                'minimum' => $this->normalizeUsageNumber($matches['minimum']),
                'maximum' => $this->normalizeUsageNumber($matches['maximum']),
                'basis' => $basis,
            ];
        }
        if (preg_match('/\\b(?:at\\s+least|minimum(?:\\s+of)?|from)\\s+(?<minimum>'.$number.')\\s*%/u', $text, $matches) === 1) {
            return ['minimum' => $this->normalizeUsageNumber($matches['minimum']), 'maximum' => null, 'basis' => $basis];
        }
        if (preg_match('/\\b(?:up\\s+to|at\\s+most|maximum(?:\\s+of)?)\\s+(?<maximum>'.$number.')\\s*%/u', $text, $matches) === 1) {
            return ['minimum' => null, 'maximum' => $this->normalizeUsageNumber($matches['maximum']), 'basis' => $basis];
        }

        return null;
    }

    /** @param array<string, mixed> $evidence @return array{minimum:?string,maximum:?string,basis:?string}|null */
    private function usageBoundsFromEvidence(array $evidence): ?array
    {
        $minimum = $evidence['recommended_min_percent'] ?? null;
        $maximum = $evidence['recommended_max_percent'] ?? null;
        if (! is_string($minimum) && ! is_string($maximum)) {
            return null;
        }

        return [
            'minimum' => is_string($minimum) ? $this->normalizeUsageNumber($minimum) : null,
            'maximum' => is_string($maximum) ? $this->normalizeUsageNumber($maximum) : null,
            'basis' => is_string($evidence['percentage_basis'] ?? null)
                ? $evidence['percentage_basis']
                : null,
        ];
    }

    private function normalizeUsageNumber(string $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    /** @param array<string, mixed> $claim @param array<string, mixed> $context */
    private function validateFactSupport(array $claim, string $section, array $context): bool
    {
        if ($claim['evidence_indexes'] !== [] || $claim['fact_paths'] === []) {
            return false;
        }

        foreach ($claim['fact_paths'] as $path) {
            if (! is_string($path)
                || ! $this->isAllowedFactPath($path, $section)
                || ! Arr::has($context, $path)) {
                return false;
            }

            if ($path === 'current.canonical.info_markdown'
                && ! $this->currentGuidanceContainsClaim((string) $claim['text'], (string) Arr::get($context, $path))) {
                return false;
            }
        }

        return true;
    }

    private function isAllowedFactPath(string $path, string $section): bool
    {
        if ($section === 'formulation_use') {
            return $path === 'current.canonical.info_markdown';
        }

        if ($section === 'soapmaking') {
            return $path === 'current.canonical.info_markdown'
                || $path === 'current.soap_chemistry'
                || $path === 'editorial_context.trusted_soap_chemistry'
                || str_starts_with($path, 'current.soap_chemistry.')
                || str_starts_with($path, 'editorial_context.trusted_soap_chemistry.');
        }

        return str_starts_with($path, 'proposal.')
            || str_starts_with($path, 'editorial_context.')
            || str_starts_with($path, 'current.canonical.');
    }

    private function heading(string $section): string
    {
        $headings = config('ingredient-enrichment.guidance.required_headings', ['Overview', 'Formulation use']);

        return $section === 'overview'
            ? (string) ($headings[0] ?? 'Overview')
            : (string) ($headings[1] ?? 'Formulation use');
    }

    /** @param array<mixed> $values @return list<string> */
    private function stringList(array $values): array
    {
        return collect($values)->map(fn (string $value): string => $value)->values()->all();
    }

    private function wordCount(string $markdown): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’][\p{L}\p{N}]+)*/u', strip_tags($markdown), $matches);

        return count($matches[0] ?? []);
    }

    private function visibleCharacterCount(string $markdown): int
    {
        $text = preg_replace('/^#{1,6}\h+/mu', '', strip_tags($markdown)) ?? $markdown;
        $text = preg_replace('/\[([^\]]+)]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[*_~`]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen(trim($text));
    }

    private function currentGuidanceContainsClaim(string $claim, string $currentGuidance): bool
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/^#{1,6}\h+/mu', '', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

            return mb_strtolower(trim($value));
        };

        return str_contains($normalize($currentGuidance), $normalize($claim));
    }

    private function invalid(): never
    {
        throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
    }
}
