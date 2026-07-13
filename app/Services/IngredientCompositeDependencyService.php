<?php

namespace App\Services;

use App\Models\IngredientComponent;
use Illuminate\Support\Collection;

class IngredientCompositeDependencyService
{
    /**
     * @param  Collection<int, int>  $sourceIngredientIds
     * @return array<int, Collection<int, int>>
     */
    public function ingredientIdsBySource(Collection $sourceIngredientIds, bool $lockForUpdate = false): array
    {
        $sourceIngredientIds = $sourceIngredientIds
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->unique()
            ->values();
        $ingredientIdsBySource = $sourceIngredientIds
            ->mapWithKeys(fn (int $ingredientId): array => [$ingredientId => collect([$ingredientId])])
            ->all();
        $sourceIdsByFrontierIngredientId = $sourceIngredientIds
            ->mapWithKeys(fn (int $ingredientId): array => [$ingredientId => collect([$ingredientId])]);

        while ($sourceIdsByFrontierIngredientId->isNotEmpty()) {
            $componentQuery = IngredientComponent::query()
                ->whereIn('component_ingredient_id', $sourceIdsByFrontierIngredientId->keys())
                ->orderBy('id');

            if ($lockForUpdate) {
                $componentQuery->lockForUpdate();
            }

            $nextFrontier = collect();

            $componentQuery
                ->get(['id', 'ingredient_id', 'component_ingredient_id'])
                ->each(function (IngredientComponent $component) use (
                    $sourceIdsByFrontierIngredientId,
                    &$ingredientIdsBySource,
                    $nextFrontier,
                ): void {
                    $sourceIds = $sourceIdsByFrontierIngredientId->get($component->component_ingredient_id, collect());

                    foreach ($sourceIds as $sourceId) {
                        if ($ingredientIdsBySource[$sourceId]->contains($component->ingredient_id)) {
                            continue;
                        }

                        $ingredientIdsBySource[$sourceId]->push($component->ingredient_id);
                        $nextFrontier->put(
                            $component->ingredient_id,
                            $nextFrontier->get($component->ingredient_id, collect())->push($sourceId)->unique()->values(),
                        );
                    }
                });

            $sourceIdsByFrontierIngredientId = $nextFrontier;
        }

        return collect($ingredientIdsBySource)
            ->map(fn (Collection $ingredientIds): Collection => $ingredientIds->unique()->values())
            ->all();
    }

    /**
     * @return array{ingredient_ids: Collection<int, int>, ancestor_ids: Collection<int, int>}
     */
    public function forSource(int $sourceIngredientId, bool $lockForUpdate = false): array
    {
        $ingredientIds = $this->ingredientIdsBySource(collect([$sourceIngredientId]), $lockForUpdate)[$sourceIngredientId];

        return [
            'ingredient_ids' => $ingredientIds,
            'ancestor_ids' => $ingredientIds
                ->reject(fn (int $ingredientId): bool => $ingredientId === $sourceIngredientId)
                ->values(),
        ];
    }
}
