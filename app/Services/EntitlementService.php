<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\OwnerType;
use Illuminate\Validation\ValidationException;

class EntitlementService
{
    /**
     * @return array<string, array{used: int, limit: int|null, remaining: int|null, allowed: bool}>
     */
    public function usageFor(User $user): array
    {
        $limits = $this->limitsFor($user);

        return [
            'saved_recipes' => $this->usageLine(
                used: $this->savedRecipeCount($user),
                limit: $limits['saved_recipes'] ?? null,
            ),
            'private_ingredients' => $this->usageLine(
                used: $this->privateIngredientCount($user),
                limit: $limits['private_ingredients'] ?? null,
            ),
            'production_batches' => $this->usageLine(
                used: $this->productionBatchCount($user),
                limit: $limits['production_batches'] ?? null,
            ),
        ];
    }

    public function canCreateRecipe(User $user): bool
    {
        return $this->usageFor($user)['saved_recipes']['allowed'];
    }

    public function canCreatePrivateIngredient(User $user): bool
    {
        return $this->usageFor($user)['private_ingredients']['allowed'];
    }

    public function canCreateProductionBatch(User $user): bool
    {
        return $this->usageFor($user)['production_batches']['allowed'];
    }

    public function planFor(User $user): ?Plan
    {
        return $this->currentPlanFor($user);
    }

    public function assignDefaultPlan(User $user): void
    {
        $plan = Plan::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();

        if (! $plan instanceof Plan) {
            return;
        }

        $user->entitlements()->firstOrCreate(
            [
                'plan_id' => $plan->id,
                'status' => 'active',
            ],
            [
                'source' => 'registration',
                'starts_at' => now(),
            ],
        );
    }

    public function assertCanCreateRecipe(User $user): void
    {
        $usage = $this->usageFor($user)['saved_recipes'];

        if ($usage['allowed']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => "Your current plan allows {$usage['limit']} saved recipes.",
        ]);
    }

    public function assertCanCreatePrivateIngredient(User $user): void
    {
        $usage = $this->usageFor($user)['private_ingredients'];

        if ($usage['allowed']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => "Your current plan allows {$usage['limit']} private ingredients.",
        ]);
    }

    public function assertCanCreateProductionBatch(User $user): void
    {
        $usage = $this->usageFor($user)['production_batches'];

        if ($usage['allowed']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => "Your current plan allows {$usage['limit']} saved production batches.",
        ]);
    }

    /**
     * @return array<string, int|null>
     */
    private function limitsFor(User $user): array
    {
        $plan = $this->currentPlanFor($user);

        if (! $plan instanceof Plan) {
            return [];
        }

        return $plan->limits
            ->mapWithKeys(fn (PlanLimit $limit): array => [$limit->key => $limit->value])
            ->all();
    }

    private function currentPlanFor(User $user): ?Plan
    {
        $entitlement = $user->entitlements()
            ->active()
            ->with('plan.limits')
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if ($entitlement?->plan instanceof Plan) {
            return $entitlement->plan;
        }

        return Plan::query()
            ->with('limits')
            ->where('is_default', true)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, allowed: bool}
     */
    private function usageLine(int $used, ?int $limit): array
    {
        if ($limit === null) {
            return [
                'used' => $used,
                'limit' => null,
                'remaining' => null,
                'allowed' => true,
            ];
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'allowed' => $used < $limit,
        ];
    }

    private function savedRecipeCount(User $user): int
    {
        return Recipe::withoutGlobalScopes()
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id)
            ->whereNull('archived_at')
            ->count();
    }

    private function privateIngredientCount(User $user): int
    {
        return Ingredient::withoutGlobalScopes()
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id)
            ->count();
    }

    private function productionBatchCount(User $user): int
    {
        return ProductionBatch::query()
            ->where('user_id', $user->id)
            ->count();
    }
}
