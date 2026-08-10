<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use Illuminate\Support\Collection;

class ProductionCalculatedRequirementBuilder
{
    /**
     * @param  Collection<int, array<string, mixed>>  $formulaLines
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Collection $formulaLines, int $startingSortOrder): Collection
    {
        $sortOrder = $startingSortOrder;

        return $formulaLines
            ->filter(fn (array $line): bool => in_array(
                $line['component'] ?? null,
                [ProductionFormulaComponent::Naoh, ProductionFormulaComponent::Koh],
                true,
            ))
            ->map(function (array $line) use (&$sortOrder): array {
                return [
                    'ingredient_id' => $line['ingredient_id'],
                    'packaging_item_id' => null,
                    'recipe_item_id' => null,
                    'recipe_version_packaging_item_id' => null,
                    'kind' => 'ingredient',
                    'required_mass_grams' => $line['planned_mass_grams'],
                    'required_units' => null,
                    'subject_name_snapshot' => $line['subject_name_snapshot'],
                    'phase_key_snapshot' => $line['phase_key_snapshot'],
                    'phase_name_snapshot' => $line['phase_name_snapshot'],
                    'percentage_snapshot' => $line['basis_percentage_snapshot'],
                    'components_per_unit_snapshot' => null,
                    'unit_snapshot' => 'g',
                    'note_snapshot' => $line['note_snapshot'] ?? null,
                    'sort_order' => $sortOrder++,
                ];
            })
            ->values();
    }
}
