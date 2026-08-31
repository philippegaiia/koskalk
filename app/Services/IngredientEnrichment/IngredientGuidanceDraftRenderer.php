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
        $blocks = [];

        foreach ($sections as $section => $heading) {
            $claims = $draft[$section];
            if (! is_array($claims)) {
                $this->invalid();
            }

            if ($section === 'soapmaking' && $claims === []) {
                continue;
            }

            $texts = [];
            foreach ($claims as $index => $claim) {
                if (! is_array($claim)) {
                    $this->invalid();
                }

                $texts[] = $this->validateClaim($claim, $section, $index, $context);
            }

            $blocks[] = '## '.$heading.($texts === [] ? '' : "\n\n".implode(' ', $texts));
        }

        $markdown = implode("\n\n", $blocks);
        $maximumWords = (int) config('ingredient-enrichment.guidance.maximum_words', 160);
        if ($maximumWords > 0 && $this->wordCount($markdown) > $maximumWords) {
            $this->invalid();
        }

        return [
            'info_markdown' => $markdown,
            'warnings' => $this->stringList($draft['warnings']),
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
    private function validateClaim(array $claim, string $section, int|string $index, array $context): string
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
            || ! is_string($claimType)
            || ! in_array($claimType, config('ingredient-enrichment.openai.guidance_research.allowed_claim_types', []), true)
            || ! is_string($supportType)
            || ! in_array($supportType, ['evidence', 'fact'], true)
            || ! is_string($usageApplication)
            || ! in_array($usageApplication, config('ingredient-enrichment.openai.guidance_research.allowed_usage_applications', []), true)
            || ! is_array($evidenceIndexes)
            || ! is_array($factPaths)) {
            $this->invalid();
        }

        if ($claimType === 'usage') {
            if (($section === 'formulation_use' && $usageApplication !== 'cosmetics')
                || ($section === 'soapmaking' && $usageApplication !== 'soapmaking')
                || ! in_array($section, ['formulation_use', 'soapmaking'], true)) {
                $this->invalid();
            }
        } elseif ($usageApplication !== 'not_applicable') {
            $this->invalid();
        }

        if ($section === 'formulation_use' && $supportType !== 'evidence') {
            $this->invalid();
        }

        if ($section === 'soapmaking' && $claimType !== 'soapmaking' && $claimType !== 'usage') {
            $this->invalid();
        }

        if ($supportType === 'evidence') {
            $this->validateEvidenceSupport($claim, $context);
        } else {
            $this->validateFactSupport($claim, $section, $context);
        }

        if ($section === 'soapmaking'
            && $supportType === 'fact'
            && $claimType !== 'soapmaking') {
            $this->invalid();
        }

        return $text;
    }

    /** @param array<string, mixed> $claim @param array<string, mixed> $context */
    private function validateEvidenceSupport(array $claim, array $context): void
    {
        if ($claim['fact_paths'] !== [] || $claim['evidence_indexes'] === []) {
            $this->invalid();
        }

        $evidence = is_array($context['guidance_evidence'] ?? null) ? $context['guidance_evidence'] : [];
        foreach ($claim['evidence_indexes'] as $index) {
            if (! is_int($index) || ! array_key_exists($index, $evidence) || ! is_array($evidence[$index])) {
                $this->invalid();
            }

            $row = $evidence[$index];
            if (($row['claim_type'] ?? null) !== $claim['claim_type']) {
                $this->invalid();
            }

            $this->validateEvidenceBoundaries($claim, $row, $context);

            if ($claim['claim_type'] === 'usage') {
                if (($row['evidence_kind'] ?? null) !== 'formulation_recommendation'
                    || ($row['usage_application'] ?? null) !== $claim['usage_application']
                    || ($row['recommended_min_percent'] ?? null) === null && ($row['recommended_max_percent'] ?? null) === null
                    || ($row['percentage_basis'] ?? 'not_applicable') === 'not_applicable') {
                    $this->invalid();
                }
            } elseif (($row['usage_application'] ?? 'not_applicable') !== 'not_applicable') {
                $this->invalid();
            }
        }
    }

    /**
     * Keep evidence-linked claims bounded to what the source can support.
     *
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $context
     */
    private function validateEvidenceBoundaries(array $claim, array $evidence, array $context): void
    {
        $text = mb_strtolower((string) ($claim['text'] ?? ''));
        $claimType = (string) ($claim['claim_type'] ?? '');

        if ($claimType !== 'solubility' && $this->isGenericWaterSolubilityClaim($text)) {
            $this->invalid();
        }

        if ($this->hasUnresolvedQuestionForClaim($claimType, $context['guidance_unresolved_questions'] ?? [])) {
            $this->invalid();
        }

        if (($evidence['evidence_kind'] ?? null) === 'experimental_observation'
            && ! $this->hasBoundedEvidenceQualifier($text)) {
            $this->invalid();
        }

        if ($this->isUniversalEmulsifierClaim($text)) {
            $this->invalid();
        }
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

    private function hasUnresolvedQuestionForClaim(string $claimType, mixed $questions): bool
    {
        if (! is_array($questions) || $questions === []) {
            return false;
        }

        $keywords = match ($claimType) {
            'dispersion' => ['dispers', 'emulsion', 'emulsifier', 'pickering'],
            'processing' => ['process', 'phase', 'temperat', 'heat', 'incorporat'],
            'solubility' => ['solub', 'water', 'aqueous', 'dissolv'],
            'soapmaking' => ['soap', 'saponif', 'fatty acid', 'bar'],
            'usage' => ['use level', 'percentage', 'range', 'application'],
            default => [],
        };

        if ($keywords === []) {
            return false;
        }

        foreach ($questions as $question) {
            if (! is_string($question)) {
                continue;
            }

            $normalized = mb_strtolower($question);
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $claim @param array<string, mixed> $context */
    private function validateFactSupport(array $claim, string $section, array $context): void
    {
        if ($claim['evidence_indexes'] !== [] || $claim['fact_paths'] === []) {
            $this->invalid();
        }

        foreach ($claim['fact_paths'] as $path) {
            if (! is_string($path)
                || ! $this->isAllowedFactPath($path, $section)
                || ! Arr::has($context, $path)) {
                $this->invalid();
            }
        }
    }

    private function isAllowedFactPath(string $path, string $section): bool
    {
        if ($section === 'formulation_use') {
            return false;
        }

        if ($section === 'soapmaking') {
            return $path === 'current.soap_chemistry'
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

    private function invalid(): never
    {
        throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
    }
}
