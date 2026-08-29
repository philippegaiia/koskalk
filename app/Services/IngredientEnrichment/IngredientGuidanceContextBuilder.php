<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use Carbon\CarbonImmutable;

class IngredientGuidanceContextBuilder
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
    ) {}

    /**
     * Build the reusable, no-web context for a guidance refresh.
     *
     * @return array<string, mixed>
     */
    public function build(Ingredient $ingredient): array
    {
        $snapshot = $this->snapshots->snapshot($ingredient);
        $persistedEvidence = $this->persistedEvidence($ingredient);
        $legacyEvidence = $persistedEvidence === [] ? $this->legacyBatchEvidence($ingredient) : [];
        $evidence = $persistedEvidence !== [] ? $persistedEvidence : $legacyEvidence;
        $warnings = $evidence === []
            ? [(string) __('ingredient_enrichment.warnings.guidance_evidence_missing')]
            : [];

        return [
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => $this->snapshots->fingerprint($ingredient),
            'current' => $snapshot,
            'guidance_evidence' => $evidence,
            'warnings' => $warnings,
            'requested_output' => [
                'guidance' => config('ingredient-enrichment.guidance'),
            ],
        ];
    }

    /** @return list<array{source_name:string,source_url:string,summary:string,source_tier:string,retrieved_at:string}> */
    private function persistedEvidence(Ingredient $ingredient): array
    {
        return $this->normalizeEvidence(data_get($ingredient->source_data, 'enrichment.guidance.evidence', []));
    }

    /** @return list<array{source_name:string,source_url:string,summary:string,source_tier:string,retrieved_at:string}> */
    private function legacyBatchEvidence(Ingredient $ingredient): array
    {
        $items = IngredientEnrichmentBatchItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('status', IngredientEnrichmentItemStatus::Applied->value)
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get(['research_stages', 'applied_at']);

        foreach ($items as $item) {
            $candidateEvidence = data_get($item->research_stages, 'ai_guidance_research.data.candidate_evidence', []);
            $evidence = $this->normalizeEvidence($candidateEvidence, $item->applied_at);
            if ($evidence !== []) {
                return $evidence;
            }
        }

        return [];
    }

    /**
     * @return list<array{source_name:string,source_url:string,summary:string,source_tier:string,retrieved_at:string}>
     */
    private function normalizeEvidence(mixed $rows, ?CarbonImmutable $fallbackRetrievedAt = null): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row)
                && ($row['field'] ?? 'proposal.info_markdown') === 'proposal.info_markdown'
                && is_string($row['source_name'] ?? null)
                && is_string($row['source_url'] ?? null)
                && is_string($row['summary'] ?? null))
            ->map(fn (array $row): array => [
                'source_name' => trim((string) $row['source_name']),
                'source_url' => trim((string) $row['source_url']),
                'summary' => trim((string) $row['summary']),
                'source_tier' => 'editorial',
                'retrieved_at' => is_string($row['retrieved_at'] ?? null)
                    ? trim($row['retrieved_at'])
                    : ($fallbackRetrievedAt?->toIso8601String() ?? CarbonImmutable::now()->toIso8601String()),
            ])
            ->filter(fn (array $row): bool => $row['source_name'] !== ''
                && $row['source_url'] !== ''
                && $row['summary'] !== '')
            ->unique('source_url')
            ->values()
            ->all();
    }
}
