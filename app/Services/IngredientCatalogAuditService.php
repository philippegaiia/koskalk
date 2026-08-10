<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Support\CosIngFunctionDataset;
use App\Support\InciName;
use App\Support\IngredientCatalogConsolidationDataset;
use App\Support\IngredientCatalogTaxonomyDataset;
use Illuminate\Support\Collection;

class IngredientCatalogAuditService
{
    public function __construct(
        private readonly IngredientCatalogTaxonomyDataset $taxonomy,
        private readonly IngredientCatalogConsolidationDataset $consolidation,
        private readonly CosIngFunctionDataset $cosIng,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function audit(): array
    {
        $result = [
            'invalid_taxonomy' => [],
            'missing_platform_subtype' => [],
            'soap_trust_without_koh_sap' => [],
            'aromatic_without_compliance' => [],
            'cosing_exact_matches' => [],
            'cosing_no_match' => [],
            'cosing_ambiguous_match' => [],
            'cosing_invalid' => [],
            'manual_only_platform_functions' => [],
            'unresolved_consolidation' => [],
            'conflicting_duplicate_prices' => [],
        ];

        $platformIngredients = Ingredient::query()
            ->whereNull('owner_type')
            ->with(['sapProfile', 'functions'])
            ->orderBy('catalog_key')
            ->get();

        foreach ($platformIngredients as $ingredient) {
            $assignment = $this->taxonomy->assignmentFor($ingredient);

            if ($assignment === null
                || $ingredient->category !== $assignment['category']
                || $ingredient->subcategory !== $assignment['subcategory']) {
                $result['invalid_taxonomy'][] = (string) $ingredient->catalog_key;
            }

            if ($ingredient->category !== IngredientCategory::Other && $ingredient->subcategory === null) {
                $result['missing_platform_subtype'][] = (string) $ingredient->catalog_key;
            }

            if ($ingredient->is_soap_saponification_trusted && $ingredient->sapProfile?->koh_sap_value === null) {
                $result['soap_trust_without_koh_sap'][] = (string) $ingredient->catalog_key;
            }

            if ($ingredient->category === IngredientCategory::AromaticMaterials
                && ! $ingredient->requires_aromatic_compliance) {
                $result['aromatic_without_compliance'][] = (string) $ingredient->catalog_key;
            }

            if ($ingredient->functions->isNotEmpty()
                && $ingredient->functions->every(fn ($function): bool => ($function->pivot->source ?? 'manual') === 'manual')) {
                $result['manual_only_platform_functions'][] = (string) $ingredient->catalog_key;
            }
        }

        $cosIngAssignments = collect($this->cosIng->all());

        foreach ($cosIngAssignments as $assignment) {
            $ingredient = $platformIngredients->firstWhere('catalog_key', $assignment['catalog_key']);

            if (! $ingredient instanceof Ingredient
                || InciName::normalize($ingredient->inci_name) !== InciName::normalize($assignment['inci_name'])) {
                $result['cosing_invalid'][] = $assignment['catalog_key'];

                continue;
            }

            $result['cosing_exact_matches'][] = $assignment['catalog_key'];
        }

        $assignedCatalogKeys = $cosIngAssignments->pluck('catalog_key');
        $ingredientsByInci = $platformIngredients
            ->filter(fn (Ingredient $ingredient): bool => InciName::normalize($ingredient->inci_name) !== '')
            ->groupBy(fn (Ingredient $ingredient): string => InciName::normalize($ingredient->inci_name));

        foreach ($ingredientsByInci as $ingredients) {
            if ($ingredients->count() > 1) {
                array_push(
                    $result['cosing_ambiguous_match'],
                    ...$ingredients->pluck('catalog_key')->map(fn (mixed $key): string => (string) $key)->all(),
                );

                continue;
            }

            $catalogKey = (string) $ingredients->first()->catalog_key;

            if (! $assignedCatalogKeys->contains($catalogKey)) {
                $result['cosing_no_match'][] = $catalogKey;
            }
        }

        foreach ($this->consolidation->all() as $decision) {
            if ($decision['action'] === 'review'
                && $platformIngredients->contains('catalog_key', $decision['source_catalog_key'])) {
                $result['unresolved_consolidation'][] = $decision['source_catalog_key'];
            }

            if ($decision['target_catalog_key'] !== null) {
                array_push(
                    $result['conflicting_duplicate_prices'],
                    ...$this->conflictingPricesForDecision($decision, $platformIngredients),
                );
            }
        }

        return $result;
    }

    /**
     * @param  array<string, list<string>>  $result
     */
    public function hasBlockingIssues(array $result): bool
    {
        foreach ([
            'invalid_taxonomy',
            'missing_platform_subtype',
            'soap_trust_without_koh_sap',
            'aromatic_without_compliance',
            'cosing_invalid',
            'unresolved_consolidation',
            'conflicting_duplicate_prices',
        ] as $group) {
            if ($result[$group] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{action:string,source_catalog_key:string,target_catalog_key:?string,reason:string}  $decision
     * @param  Collection<int, Ingredient>  $platformIngredients
     * @return list<string>
     */
    private function conflictingPricesForDecision(array $decision, $platformIngredients): array
    {
        $source = $platformIngredients->firstWhere('catalog_key', $decision['source_catalog_key']);
        $target = $platformIngredients->firstWhere('catalog_key', $decision['target_catalog_key']);

        if (! $source instanceof Ingredient || ! $target instanceof Ingredient) {
            return [];
        }

        $pricesByWorkspace = CurrentMaterialPrice::query()
            ->whereIn('ingredient_id', [$source->id, $target->id])
            ->orderBy('workspace_id')
            ->get()
            ->groupBy('workspace_id');

        return $pricesByWorkspace
            ->filter(function ($prices) use ($source, $target): bool {
                $sourcePrice = $prices->firstWhere('ingredient_id', $source->id);
                $targetPrice = $prices->firstWhere('ingredient_id', $target->id);

                return $sourcePrice instanceof CurrentMaterialPrice
                    && $targetPrice instanceof CurrentMaterialPrice
                    && ($sourcePrice->currency !== $targetPrice->currency
                        || $sourcePrice->price_per_canonical_unit !== $targetPrice->price_per_canonical_unit);
            })
            ->keys()
            ->map(fn (int|string $workspaceId): string => sprintf(
                '%s -> %s @ workspace %s',
                $decision['source_catalog_key'],
                $decision['target_catalog_key'],
                $workspaceId,
            ))
            ->values()
            ->all();
    }
}
