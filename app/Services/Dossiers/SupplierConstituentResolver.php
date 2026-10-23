<?php

namespace App\Services\Dossiers;

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Models\DossierEvidenceConstituent;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientSubstanceEntry;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Support\DecimalMath;
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
        $silent = $this->suppliersWithoutDeclaration($workspace, $ingredient, ConstituentKind::Allergen);
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
        $silent = $this->suppliersWithoutDeclaration($workspace, $ingredient, ConstituentKind::Impurity);

        return $this->worstCase($workspace, $ingredient, ConstituentKind::Impurity, 'substance_id', $silent)->values();
    }

    /**
     * Restricted substances: regulatory conformity certificates first; the platform composition (substance entries
     * with a stated concentration) only as a flagged fallback.
     *
     * @return Collection<int, array{substance_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function restrictedSubstances(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->suppliersWithoutDeclaration($workspace, $ingredient, ConstituentKind::RestrictedSubstance);
        $declared = $this->worstCase($workspace, $ingredient, ConstituentKind::RestrictedSubstance, 'substance_id', $silent);

        $fallback = IngredientSubstanceEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('concentration_percent')
            ->whereNotIn('substance_id', $declared->keys()->all())
            ->get()
            ->map(fn (IngredientSubstanceEntry $entry): array => [
                'substance_id' => $entry->substance_id,
                'max_value' => DecimalMath::of($entry->concentration_percent),
                'unit' => ConstituentKind::RestrictedSubstance->unit(),
                'source' => 'platform',
                'evidence_item_ids' => [],
                'suppliers_without_declaration' => $silent,
                'needs_justification' => true,
            ]);

        return $declared->values()->concat($fallback)->values();
    }

    /**
     * Validated suppliers of the ingredient that have not declared this kind of content. Independent of the per-substance
     * rows, so "nobody declared anything" is reported instead of looking like "nothing to declare".
     *
     * @return list<int>
     */
    public function suppliersWithoutDeclaration(Workspace $workspace, Ingredient $ingredient, ConstituentKind $kind): array
    {
        return match ($kind) {
            // A complete allergen declaration listing nothing does declare "no allergens".
            ConstituentKind::Allergen => $this->qualificationsWithout($workspace, $ingredient, null, EvidenceType::AllergenDeclaration),
            // A technical sheet that does not mention impurities is not a declaration of none: an explicit value is required.
            ConstituentKind::Impurity => $this->qualificationsWithout($workspace, $ingredient, ConstituentKind::Impurity, EvidenceType::TechnicalSheet, EvidenceType::CertificateOfAnalysis),
            ConstituentKind::RestrictedSubstance => $this->qualificationsWithout($workspace, $ingredient, null, EvidenceType::RegulatoryConformityCertificate),
        };
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
     * Validated qualifications of the ingredient with no current, fully transcribed document of the given types.
     * A document that is merely uploaded, or transcribed only in part, does not clear the flag. When $requiredKind
     * is given, the document must also state at least one explicit value of that kind (zero included).
     *
     * @return list<int>
     */
    private function qualificationsWithout(Workspace $workspace, Ingredient $ingredient, ?ConstituentKind $requiredKind, EvidenceType ...$types): array
    {
        return SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->whereDoesntHave('evidenceItems', fn ($query) => $query
                ->whereNull('retired_at')
                ->where('constituents_complete', true)
                ->whereIn('type', array_map(fn (EvidenceType $type): string => $type->value, $types))
                ->when($requiredKind !== null, fn ($items) => $items->whereHas('constituents', fn ($rows) => $rows->where('kind', $requiredKind->value))))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
