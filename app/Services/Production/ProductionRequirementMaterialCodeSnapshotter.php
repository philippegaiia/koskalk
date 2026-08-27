<?php

namespace App\Services\Production;

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

        return $requirements->map(function (array $requirement) use ($codes): array {
            $ingredientId = $requirement['ingredient_id'] ?? null;

            return [
                ...$requirement,
                'material_code_snapshot' => $ingredientId === null
                    ? null
                    : $codes->get((int) $ingredientId),
            ];
        });
    }
}
