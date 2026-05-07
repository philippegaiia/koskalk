<?php

namespace App\Services;

use App\Models\Ingredient;

class IngredientFormulaContextResolver
{
    /**
     * @var array<int, Ingredient|null>
     */
    private array $ingredientCache = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $relations
     * @return array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>
     */
    public function resolve(array $payload, array $relations = []): array
    {
        $this->ingredientCache = [];
        $relations = $this->ingredientGraphRelations($relations);
        $this->preloadIngredientsForPayload($payload, $relations);

        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];
        $contexts = [];

        foreach ($this->phaseItemRows($phaseItems) as $phaseKey => $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $ingredientId = filled($row['ingredient_id'] ?? null)
                    ? (int) $row['ingredient_id']
                    : null;

                $ingredient = $ingredientId === null
                    ? null
                    : $this->ingredientById($ingredientId, $relations);

                $contexts = [
                    ...$contexts,
                    ...$this->expandedContexts(
                        $phaseKey,
                        $this->rowWeight($row, $payload),
                        $ingredient,
                        $ingredient?->display_name ?? trim((string) ($row['name'] ?? '')),
                        $relations,
                    ),
                ];
            }
        }

        return array_values(array_filter(
            $contexts,
            fn (array $context): bool => $context['weight'] > 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>
     */
    public function raw(array $payload): array
    {
        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];
        $contexts = [];

        foreach ($this->phaseItemRows($phaseItems) as $phaseKey => $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $contexts[] = [
                    'phase_key' => $phaseKey,
                    'weight' => $this->rowWeight($row, $payload),
                    'ingredient' => null,
                    'ingredient_name' => trim((string) ($row['name'] ?? '')),
                    'is_user_owned' => false,
                ];
            }
        }

        return array_values(array_filter(
            $contexts,
            fn (array $context): bool => $context['weight'] > 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $relations
     */
    private function preloadIngredientsForPayload(array $payload, array $relations): void
    {
        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];
        $ingredientIds = [];

        foreach ($this->phaseItemRows($phaseItems) as $rows) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! filled($row['ingredient_id'] ?? null)) {
                    continue;
                }

                $ingredientIds[] = (int) $row['ingredient_id'];
            }
        }

        $this->preloadIngredientGraph($ingredientIds, $relations);
    }

    /**
     * @param  array<string, mixed>  $phaseItems
     * @return array<string, array<int, mixed>>
     */
    private function phaseItemRows(array $phaseItems): array
    {
        return collect($phaseItems)
            ->filter(fn (mixed $rows, mixed $phaseKey): bool => is_string($phaseKey) && is_array($rows))
            ->map(fn (array $rows): array => $rows)
            ->all();
    }

    /**
     * @param  array<int, int>  $ingredientIds
     * @param  array<int, string>  $relations
     */
    private function preloadIngredientGraph(array $ingredientIds, array $relations): void
    {
        $pendingIds = collect($ingredientIds)
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();

        while ($pendingIds !== []) {
            $idsToLoad = array_values(array_filter(
                $pendingIds,
                fn (int $id): bool => ! array_key_exists($id, $this->ingredientCache),
            ));

            if ($idsToLoad === []) {
                break;
            }

            $loadedIngredients = Ingredient::query()
                ->with($relations)
                ->whereKey($idsToLoad)
                ->get()
                ->keyBy('id');

            foreach ($idsToLoad as $ingredientId) {
                $this->ingredientCache[$ingredientId] = $loadedIngredients->get($ingredientId);
            }

            $pendingIds = $loadedIngredients
                ->flatMap(fn (Ingredient $ingredient) => $ingredient->components->pluck('component_ingredient_id'))
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->unique()
                ->values()
                ->all();
        }
    }

    /**
     * @param  array<int, string>  $relations
     */
    private function ingredientById(int $ingredientId, array $relations): ?Ingredient
    {
        if (! array_key_exists($ingredientId, $this->ingredientCache)) {
            $this->ingredientCache[$ingredientId] = Ingredient::query()
                ->with($relations)
                ->find($ingredientId);
        }

        return $this->ingredientCache[$ingredientId];
    }

    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    private function ingredientGraphRelations(array $relations): array
    {
        return array_values(array_unique([
            ...$relations,
            'components',
        ]));
    }

    /**
     * @param  array<int, string>  $relations
     * @param  array<int, int>  $ancestry
     * @return array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>
     */
    private function expandedContexts(
        string $phaseKey,
        float $weight,
        ?Ingredient $ingredient,
        string $fallbackName,
        array $relations,
        array $ancestry = [],
    ): array {
        if ($weight <= 0) {
            return [];
        }

        if (! $ingredient instanceof Ingredient) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => null,
                'ingredient_name' => $fallbackName,
                'is_user_owned' => false,
            ]];
        }

        if (in_array($ingredient->id, $ancestry, true)) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => $ingredient,
                'ingredient_name' => $ingredient->display_name,
                'is_user_owned' => $ingredient->owner_type !== null,
            ]];
        }

        $validComponents = $ingredient->components
            ->filter(fn ($component): bool => $component->component_ingredient_id !== null && (float) $component->percentage_in_parent > 0)
            ->values();

        if ($validComponents->isEmpty()) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => $ingredient,
                'ingredient_name' => $ingredient->display_name,
                'is_user_owned' => $ingredient->owner_type !== null,
            ]];
        }

        $expandedContexts = [];
        $nextAncestry = [...$ancestry, $ingredient->id];

        foreach ($validComponents as $component) {
            $componentIngredient = $this->ingredientById((int) $component->component_ingredient_id, $relations);
            $componentWeight = $weight * (((float) $component->percentage_in_parent) / 100);

            $expandedContexts = [
                ...$expandedContexts,
                ...$this->expandedContexts(
                    $phaseKey,
                    $componentWeight,
                    $componentIngredient,
                    $componentIngredient?->display_name ?? $ingredient->display_name,
                    $relations,
                    $nextAncestry,
                ),
            ];
        }

        return $expandedContexts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     */
    private function rowWeight(array $row, array $payload): float
    {
        $explicitWeight = (float) ($row['weight'] ?? 0);

        if ($explicitWeight > 0) {
            return $explicitWeight;
        }

        $oilWeight = (float) ($payload['oil_weight'] ?? 0);
        $percentage = (float) ($row['percentage'] ?? 0);

        if ($oilWeight <= 0 || $percentage <= 0) {
            return 0;
        }

        return $oilWeight * ($percentage / 100);
    }
}
