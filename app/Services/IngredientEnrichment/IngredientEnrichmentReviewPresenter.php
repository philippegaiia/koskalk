<?php

namespace App\Services\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Str;

class IngredientEnrichmentReviewPresenter
{
    public function subjectLabel(IngredientEnrichmentBatchItem $item): string
    {
        $intakeItem = $item->intakeItem;
        if ($intakeItem !== null) {
            return $intakeItem->original_current_name
                ?? $intakeItem->original_inci_name
                ?? __('ingredient_enrichment_admin.review.unnamed_subject');
        }

        return $item->catalog_key
            ?? $item->ingredient?->display_name
            ?? $item->ingredient?->inci_name
            ?? __('ingredient_enrichment_admin.review.unnamed_subject');
    }

    /**
     * @return list<array{
     *     path:string,
     *     label:string,
     *     current:mixed,
     *     proposed:mixed,
     *     decision:string,
     *     confidence:?string,
     *     provenance:?string,
     *     provenance_reasoning:?string,
     *     evidence:list<array{title:string,url:string,source_tier:?string,confidence:?string,version:?string,retrieved_at:?string}>,
     *     conflict_explanation:?string
     * }>
     */
    public function rows(IngredientEnrichmentBatchItem $item): array
    {
        $result = is_array($item->result) ? $item->result : [];
        $plan = is_array($item->plan) ? $item->plan : [];
        $confidence = collect($result['field_confidence'] ?? [])->keyBy('field');
        $provenance = collect($result['value_provenance'] ?? [])->keyBy('field');
        $evidence = collect($result['evidence'] ?? [])->groupBy('field');
        $guidanceEvidence = collect($result['guidance_evidence'] ?? [])
            ->map(fn (mixed $row): array => is_array($row) ? [
                'field' => 'proposal.info_markdown',
                'source_name' => $row['source_name'] ?? null,
                'source_url' => $row['source_url'] ?? null,
                'source_tier' => $row['source_tier'] ?? null,
                'confidence' => 'supported',
                'source_version' => null,
                'retrieved_at' => $row['retrieved_at'] ?? null,
            ] : [])
            ->filter(fn (array $row): bool => filled($row['source_url'] ?? null))
            ->groupBy('field');
        $evidence = $evidence->put(
            'proposal.info_markdown',
            $evidence->get('proposal.info_markdown', collect())->merge($guidanceEvidence->get('proposal.info_markdown', collect())),
        );
        $evidence = $evidence->put(
            'guidance.evidence',
            $guidanceEvidence->get('proposal.info_markdown', collect()),
        );

        return collect($plan['decisions'] ?? [])
            ->filter(fn (mixed $decision): bool => is_array($decision) && is_string($decision['field'] ?? null))
            ->map(function (array $decision) use ($result, $confidence, $provenance, $evidence): array {
                $path = (string) $decision['field'];
                $evidencePath = $this->evidencePath($path, $result);
                $fieldConfidence = $confidence->get($evidencePath, $confidence->get($path));
                $confidenceValue = is_array($fieldConfidence) && is_string($fieldConfidence['confidence'] ?? null)
                    ? $fieldConfidence['confidence']
                    : null;
                $fieldProvenance = $provenance->get($evidencePath, $provenance->get($path));
                $fieldEvidence = $evidence->get($evidencePath, $evidence->get($path, collect()));

                return [
                    'path' => $path,
                    'label' => $this->label($path),
                    'current' => $decision['current'] ?? null,
                    'proposed' => $decision['proposed'] ?? null,
                    'decision' => (string) ($decision['decision'] ?? 'unchanged'),
                    'confidence' => $confidenceValue,
                    'provenance' => is_array($fieldProvenance) && is_string($fieldProvenance['kind'] ?? null)
                        ? $fieldProvenance['kind']
                        : null,
                    'provenance_reasoning' => is_array($fieldProvenance) && is_string($fieldProvenance['reasoning'] ?? null)
                        ? $fieldProvenance['reasoning']
                        : null,
                    'evidence' => collect($fieldEvidence)
                        ->filter(fn (mixed $row): bool => is_array($row) && $this->isSafeUrl($row['source_url'] ?? null))
                        ->map(fn (array $row): array => [
                            'title' => (string) ($row['source_name'] ?? $row['source_url']),
                            'url' => (string) $row['source_url'],
                            'source_tier' => is_string($row['source_tier'] ?? null) ? $row['source_tier'] : null,
                            'confidence' => is_string($row['confidence'] ?? null) ? $row['confidence'] : null,
                            'version' => is_string($row['source_version'] ?? null) ? $row['source_version'] : null,
                            'retrieved_at' => is_string($row['retrieved_at'] ?? null) ? $row['retrieved_at'] : null,
                        ])->values()->all(),
                    'conflict_explanation' => $this->conflictExplanation($confidenceValue, $result),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $result */
    private function evidencePath(string $path, array $result): string
    {
        if (preg_match('/^proposal\.(market_labels|cosing_functions)\.([^\.]+)$/', $path, $matches) === 1) {
            $collection = $matches[1];
            $key = $matches[2];
            $keyField = $collection === 'market_labels' ? 'market_code' : 'key';
            $index = collect(data_get($result, "proposal.{$collection}", []))->search(
                fn (mixed $row): bool => is_array($row) && (string) ($row[$keyField] ?? '') === $key,
            );

            return $index === false ? $path : "proposal.{$collection}.{$index}";
        }

        if (preg_match('/^proposal\.translations\.([^\.]+)\.(.+)$/', $path, $matches) === 1) {
            $index = collect(data_get($result, 'proposal.translations', []))->search(
                fn (mixed $row): bool => is_array($row) && (string) ($row['locale'] ?? '') === $matches[1],
            );

            return $index === false ? $path : "proposal.translations.{$index}.{$matches[2]}";
        }

        return $path;
    }

    private function label(string $path): string
    {
        $key = str($path)->afterLast('.')->value();
        $translationKey = $path === 'guidance.evidence'
            ? 'ingredient_enrichment_admin.review.evidence'
            : "ingredient_enrichment_admin.review.labels.{$key}";
        $translation = __($translationKey);

        return $translation === $translationKey
            ? Str::headline($key)
            : $translation;
    }

    /** @param array<string, mixed> $result */
    private function conflictExplanation(?string $confidence, array $result): ?string
    {
        if (! in_array($confidence, ['conflicting', 'unresolved'], true)) {
            return null;
        }

        return collect($result['unresolved_questions'] ?? [])
            ->merge($result['warnings'] ?? [])
            ->filter(fn (mixed $message): bool => is_string($message) && $message !== '')
            ->first();
    }

    private function isSafeUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
