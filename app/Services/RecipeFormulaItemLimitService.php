<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RecipeFormulaItemLimitService
{
    public function __construct(private readonly EntitlementService $entitlementService) {}

    public function limitFor(User $user): ?int
    {
        return $this->entitlementService->formulaItemsPerRecipeLimitFor($user);
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function count(array $normalizedPayload): int
    {
        return collect($normalizedPayload['phases'] ?? [])
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->sum(fn (array $phase): int => collect($phase['items'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->count());
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function assertCreateAllowed(User $user, array $normalizedPayload): void
    {
        $this->assertAllowed($normalizedPayload, $this->limitFor($user));
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function assertUpdateAllowed(User $user, array $normalizedPayload, Recipe $recipe): void
    {
        $limit = $this->limitFor($user);

        if ($limit === null) {
            return;
        }

        $currentCount = $this->currentRecipeItemCount($recipe);
        $this->assertAllowed($normalizedPayload, max($limit, $currentCount));
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function assertRestoreAllowed(User $user, array $normalizedPayload): void
    {
        $this->assertAllowed($normalizedPayload, $this->limitFor($user));
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    private function assertAllowed(array $normalizedPayload, ?int $limit): void
    {
        if ($limit === null) {
            return;
        }

        $count = $this->count($normalizedPayload);

        if ($count <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'formula_items' => __('workbench.messages.formula_item_limit', [
                'limit' => $limit,
                'count' => $count,
            ]),
        ]);
    }

    private function currentRecipeItemCount(Recipe $recipe): int
    {
        $currentVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', true)
            ->first();

        if (! $currentVersion instanceof RecipeVersion) {
            return 0;
        }

        return RecipeItem::withoutGlobalScopes()
            ->where('recipe_version_id', $currentVersion->id)
            ->count();
    }
}
