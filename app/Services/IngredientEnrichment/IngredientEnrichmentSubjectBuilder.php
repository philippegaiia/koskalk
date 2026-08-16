<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientEnrichmentSubject;
use App\Enums\IngredientCategory;
use App\Enums\IngredientResearchFamily;
use App\Models\Ingredient;
use App\Models\IngredientIntakeItem;

final class IngredientEnrichmentSubjectBuilder
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
    ) {}

    public function forIngredient(Ingredient $ingredient): IngredientEnrichmentSubject
    {
        $built = $this->snapshotBuilder->build($ingredient);
        $snapshot = $built['snapshot'];
        $canonical = is_array($snapshot['canonical'] ?? null) ? $snapshot['canonical'] : [];

        return new IngredientEnrichmentSubject(
            subjectType: 'ingredient',
            subjectPublicId: (string) $ingredient->public_id,
            catalogKey: $ingredient->catalog_key,
            currentName: $canonical['display_name'] ?? null,
            inciName: $canonical['inci_name'] ?? null,
            currentSnapshot: $snapshot,
            duplicateContext: [],
            duplicateResolution: null,
            researchFamily: $this->familyForCategory(
                IngredientCategory::tryFrom((string) ($canonical['category'] ?? '')),
            ),
            fingerprint: $built['fingerprint'],
            allowGapResearch: false,
        );
    }

    public function forIntake(IngredientIntakeItem $item): IngredientEnrichmentSubject
    {
        $item->loadMissing(['batch', 'existingIngredient']);
        $linked = $item->existingIngredient;
        $linkedBuilt = $linked instanceof Ingredient
            ? $this->snapshotBuilder->build($linked)
            : null;
        $snapshot = $linkedBuilt['snapshot'] ?? $this->emptySnapshot();

        if ($linkedBuilt !== null) {
            $snapshot['catalog_key'] = null;
            $snapshot['linked_catalog_key'] = $linked?->catalog_key;
        }

        $canonical = is_array($snapshot['canonical'] ?? null) ? $snapshot['canonical'] : [];
        $family = $linked instanceof Ingredient
            ? $this->familyForCategory(IngredientCategory::tryFrom((string) ($canonical['category'] ?? '')))
            : ($item->batch?->family_hint ?? IngredientResearchFamily::Other);
        $duplicateContext = is_array($item->duplicate_candidates) ? $item->duplicate_candidates : [];
        $fingerprintInput = [
            'subject_type' => 'intake',
            'subject_public_id' => (string) $item->public_id,
            'current_name' => $item->normalized_current_name,
            'inci_name' => $item->normalized_inci_name,
            'duplicate_context' => $duplicateContext,
            'duplicate_resolution' => $item->duplicate_resolution?->value,
            'research_family' => $family->value,
            'linked_snapshot_fingerprint' => $linkedBuilt['fingerprint'] ?? null,
        ];

        return new IngredientEnrichmentSubject(
            subjectType: 'intake',
            subjectPublicId: (string) $item->public_id,
            catalogKey: null,
            currentName: $item->original_current_name,
            inciName: $item->original_inci_name,
            currentSnapshot: $snapshot,
            duplicateContext: $duplicateContext,
            duplicateResolution: $item->duplicate_resolution,
            researchFamily: $family,
            fingerprint: hash('sha256', $this->snapshotBuilder->canonicalJson($fingerprintInput)),
            allowGapResearch: (bool) ($item->batch?->allow_gap_research ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot(): array
    {
        return [
            'catalog_key' => null,
            'canonical' => [
                'display_name' => null,
                'inci_name' => null,
                'category' => null,
                'subcategory' => null,
                'saponification_name' => null,
                'soap_inci_naoh_name' => null,
                'soap_inci_koh_name' => null,
                'info_markdown' => null,
                'cosing_reference' => null,
                'is_active' => false,
                'is_manufactured' => false,
                'requires_aromatic_compliance' => false,
            ],
            'identifiers' => [],
            'aliases' => [],
            'cosing_functions' => [],
            'translations' => [],
            'market_labels' => [],
        ];
    }

    private function familyForCategory(?IngredientCategory $category): IngredientResearchFamily
    {
        return match ($category) {
            IngredientCategory::Colourants => IngredientResearchFamily::Colourants,
            IngredientCategory::Lipids => IngredientResearchFamily::Lipids,
            IngredientCategory::AromaticMaterials => IngredientResearchFamily::AromaticMaterials,
            IngredientCategory::Waxes => IngredientResearchFamily::Waxes,
            default => IngredientResearchFamily::Other,
        };
    }
}
