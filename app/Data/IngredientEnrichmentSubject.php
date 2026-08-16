<?php

namespace App\Data;

use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientResearchFamily;

final readonly class IngredientEnrichmentSubject
{
    /**
     * @param  array<string, mixed>  $currentSnapshot
     * @param  list<array<string, mixed>>  $duplicateContext
     */
    public function __construct(
        public string $subjectType,
        public string $subjectPublicId,
        public ?string $catalogKey,
        public ?string $currentName,
        public ?string $inciName,
        public array $currentSnapshot,
        public array $duplicateContext,
        public ?IngredientDuplicateResolution $duplicateResolution,
        public IngredientResearchFamily $researchFamily,
        public string $fingerprint,
        public bool $allowGapResearch = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        $canonical = is_array($this->currentSnapshot['canonical'] ?? null)
            ? $this->currentSnapshot['canonical']
            : [];

        return [
            'subject_type' => $this->subjectType,
            'subject_public_id' => $this->subjectPublicId,
            'catalog_key' => $this->catalogKey,
            'display_name' => $canonical['display_name'] ?? $this->currentName,
            'inci_name' => $canonical['inci_name'] ?? $this->inciName,
            'category' => $canonical['category'] ?? null,
            'subcategory' => $canonical['subcategory'] ?? null,
            'identifiers' => is_array($this->currentSnapshot['identifiers'] ?? null)
                ? $this->currentSnapshot['identifiers']
                : [],
            'aliases' => is_array($this->currentSnapshot['aliases'] ?? null)
                ? $this->currentSnapshot['aliases']
                : [],
            'duplicate_context' => $this->duplicateContext,
            'duplicate_resolution' => $this->duplicateResolution?->value,
            'research_family' => $this->researchFamily->value,
            'allow_gap_research' => $this->allowGapResearch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_public_id' => $this->subjectPublicId,
            'catalog_key' => $this->catalogKey,
            'current_name' => $this->currentName,
            'inci_name' => $this->inciName,
            'current_snapshot' => $this->currentSnapshot,
            'duplicate_context' => $this->duplicateContext,
            'duplicate_resolution' => $this->duplicateResolution?->value,
            'research_family' => $this->researchFamily->value,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
