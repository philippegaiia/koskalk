<?php

namespace App\Services\Production;

use App\Models\PackagingItem;
use App\Models\Workspace;
use App\Services\WorkspaceIngredientCodeService;
use Illuminate\Support\Collection;

class ProductionRequirementMaterialCodeSnapshotter
{
    public function __construct(
        private readonly WorkspaceIngredientCodeService $workspaceIngredientCodes,
    ) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $requirements
     * @return Collection<int, array<string, mixed>>
     */
    public function apply(Workspace $workspace, Collection $requirements): Collection
    {
        $ingredientIds = $requirements
            ->pluck('ingredient_id')
            ->filter()
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->unique()
            ->values()
            ->all();
        $codes = $this->workspaceIngredientCodes->codesFor($workspace, $ingredientIds);
        $packagingItemIds = $requirements
            ->pluck('packaging_item_id')
            ->filter()
            ->map(fn (mixed $packagingItemId): int => (int) $packagingItemId)
            ->unique()
            ->values()
            ->all();
        $packagingCodes = $packagingItemIds === []
            ? collect()
            : PackagingItem::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $packagingItemIds)
                ->pluck('material_code', 'id');

        return $requirements->map(function (array $requirement) use ($codes, $packagingCodes): array {
            $ingredientId = $requirement['ingredient_id'] ?? null;
            $packagingItemId = $requirement['packaging_item_id'] ?? null;
            $materialCode = $ingredientId !== null
                ? $codes->get((int) $ingredientId)
                : ($packagingItemId === null ? null : $packagingCodes->get((int) $packagingItemId));

            return [
                ...$requirement,
                'material_code_snapshot' => $materialCode,
            ];
        });
    }
}
