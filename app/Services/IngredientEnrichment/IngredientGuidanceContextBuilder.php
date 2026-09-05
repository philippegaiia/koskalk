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
        private readonly IngredientGuidanceEvidencePolicy $guidanceEvidencePolicy,
    ) {}

    /**
     * Build the reusable, no-web context for a guidance refresh.
     *
     * @return array<string, mixed>
     */
    public function build(Ingredient $ingredient, bool $freshResearch = false): array
    {
        $snapshot = $this->snapshots->snapshot($ingredient);
        $persistedEvidence = $freshResearch ? [] : $this->persistedEvidence($ingredient);
        $legacyEvidence = ! $freshResearch && $persistedEvidence === [] ? $this->legacyBatchEvidence($ingredient) : [];
        $evidence = $persistedEvidence !== [] ? $persistedEvidence : $legacyEvidence;
        $warnings = $evidence === []
            ? [(string) __('ingredient_enrichment.warnings.guidance_evidence_missing')]
            : [];

        return [
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => $this->snapshots->fingerprint($ingredient),
            'current' => $snapshot,
            'guidance_evidence' => $evidence,
            'prior_guidance_evidence' => $evidence,
            'fresh_research' => $freshResearch,
            'warnings' => $warnings,
            'requested_output' => [
                'guidance' => config('ingredient-enrichment.guidance'),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function persistedEvidence(Ingredient $ingredient): array
    {
        return $this->guidanceEvidencePolicy->normalizePersisted(
            data_get($ingredient->source_data, 'enrichment.guidance.evidence', []),
        );
    }

    /** @return list<array<string,mixed>> */
    private function legacyBatchEvidence(Ingredient $ingredient): array
    {
        $items = IngredientEnrichmentBatchItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('status', IngredientEnrichmentItemStatus::Applied->value)
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get(['research_stages', 'applied_at']);

        foreach ($items as $item) {
            $candidateEvidence = data_get($item->research_stages, 'ai_guidance_research.data.guidance_evidence')
                ?? data_get($item->research_stages, 'ai_guidance_research.data.candidate_evidence', []);
            $evidence = $this->guidanceEvidencePolicy->normalizePersisted(
                $candidateEvidence,
                $item->applied_at instanceof CarbonImmutable ? $item->applied_at : null,
            );
            if ($evidence !== []) {
                return $evidence;
            }
        }

        return [];
    }
}
