<?php

namespace App\Services\Dossiers;

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Models\DossierEvidenceConstituent;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Inputs for the DIP calculations: per allergen or impurity of an ingredient, the worst case declared by the
 * workspace's validated suppliers. Supplier documents come first; the platform composition is only a flagged
 * fallback where no supplier document gives a value (owner decision, 2026-10-21).
 */
class SupplierConstituentResolver
{
    /**
     * @return Collection<int, array{allergen_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function allergens(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->qualificationsWithout($workspace, $ingredient, EvidenceType::AllergenDeclaration);
        $declared = $this->worstCase($workspace, $ingredient, ConstituentKind::Allergen, 'allergen_id', $silent);

        $fallback = IngredientAllergenEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotIn('allergen_id', $declared->keys()->all())
            ->get()
            ->map(fn (IngredientAllergenEntry $entry): array => [
                'allergen_id' => $entry->allergen_id,
                'max_value' => number_format((float) $entry->concentration_percent, 6, '.', ''),
                'unit' => 'percent',
                'source' => 'platform',
                'evidence_item_ids' => [],
                'suppliers_without_declaration' => $silent,
                'needs_justification' => true,
            ]);

        return $declared->values()->concat($fallback)->values();
    }

    /**
     * @return Collection<int, array{substance_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function impurities(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->qualificationsWithout($workspace, $ingredient, EvidenceType::TechnicalSheet, EvidenceType::CertificateOfAnalysis);

        return $this->worstCase($workspace, $ingredient, ConstituentKind::Impurity, 'substance_id', $silent)->values();
    }

    /**
     * @param  list<int>  $silent
     * @return Collection<int, array<string, mixed>>
     */
    private function worstCase(Workspace $workspace, Ingredient $ingredient, ConstituentKind $kind, string $key, array $silent): Collection
    {
        return DossierEvidenceConstituent::query()
            ->where('kind', $kind->value)
            ->whereHas('item', fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->whereNull('retired_at')
                ->whereHas('supplierQualification', fn ($qualification) => $qualification
                    ->where('ingredient_id', $ingredient->id)
                    ->where('status', SupplierQualificationStatus::Valide->value)))
            ->get()
            ->groupBy($key)
            ->map(function (Collection $rows, int|string $id) use ($key, $kind, $silent): array {
                $worst = $rows->sortByDesc(fn (DossierEvidenceConstituent $row): float => (float) $row->max_value)->first();

                return [
                    $key => (int) $id,
                    'max_value' => (string) $worst->max_value,
                    'unit' => $kind->unit(),
                    'source' => 'supplier',
                    'evidence_item_ids' => $rows->pluck('dossier_evidence_item_id')->unique()->values()->all(),
                    'suppliers_without_declaration' => $silent,
                    'needs_justification' => $silent !== [],
                ];
            });
    }

    /**
     * Validated qualifications of the ingredient with no current document of the given types.
     *
     * @return list<int>
     */
    private function qualificationsWithout(Workspace $workspace, Ingredient $ingredient, EvidenceType ...$types): array
    {
        return SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->whereDoesntHave('evidenceItems', fn ($query) => $query
                ->whereNull('retired_at')
                ->whereIn('type', array_map(fn (EvidenceType $type): string => $type->value, $types)))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
