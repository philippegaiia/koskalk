<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EntitlementService
{
    public function __construct(private readonly WorkspaceProvisioner $workspaceProvisioner) {}

    /**
     * @return array<string, array{used: int, limit: int|null, remaining: int|null, allowed: bool}>
     */
    public function usageFor(User $user): array
    {
        $workspace = $this->companyWorkspaceFor($user);
        $subscriber = $workspace?->owner ?? $user;
        $limits = $this->limitsFor($subscriber);

        return [
            'saved_recipes' => $this->usageLine(
                used: $this->savedRecipeCount($subscriber, $workspace),
                limit: $limits['saved_recipes'] ?? null,
            ),
            'private_ingredients' => $this->privateIngredientUsage($subscriber, $workspace, $limits),
            'production_batches' => $this->usageLine(
                used: $this->productionBatchCount($user),
                limit: $limits['production_batches'] ?? null,
            ),
        ];
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null, allowed: bool}
     */
    public function privateIngredientUsageFor(User $user): array
    {
        $workspace = $this->companyWorkspaceFor($user);
        $subscriber = $workspace?->owner ?? $user;

        return $this->privateIngredientUsage($subscriber, $workspace, $this->limitsFor($subscriber));
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
        $workspace = $this->companyWorkspaceFor($user);

        return $this->currentPlanFor($workspace?->owner ?? $user);
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

    /**
     * @template T
     *
     * @param  Closure(Workspace): T  $callback
     * @return T
     */
    public function withinCompanyQuotaLock(User $user, Closure $callback): mixed
    {
        $workspace = $this->workspaceProvisioner->ensureCompanyWorkspace($user);

        return DB::transaction(function () use ($callback, $workspace): mixed {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->with('owner')
                ->lockForUpdate()
                ->findOrFail($workspace->id);

            return $callback($lockedWorkspace);
        }, attempts: 5);
    }

    public function assertCanCreateRecipe(User $user): void
    {
        $this->assertUsageAllows(
            $this->usageFor($user)['saved_recipes'],
            'saved recipes',
        );
    }

    public function assertCanCreateRecipeInWorkspace(Workspace $workspace): void
    {
        $subscriber = $this->subscriberForWorkspace($workspace);
        $limits = $this->limitsFor($subscriber);

        $this->assertUsageAllows(
            $this->usageLine(
                used: $this->savedRecipeCount($subscriber, $workspace),
                limit: $limits['saved_recipes'] ?? null,
            ),
            'saved recipes',
        );
    }

    public function assertCanCreatePrivateIngredient(User $user): void
    {
        $this->assertUsageAllows(
            $this->usageFor($user)['private_ingredients'],
            'private ingredients',
        );
    }

    public function assertCanCreatePrivateIngredientInWorkspace(Workspace $workspace): void
    {
        $subscriber = $this->subscriberForWorkspace($workspace);

        $this->assertUsageAllows(
            $this->privateIngredientUsage($subscriber, $workspace, $this->limitsFor($subscriber)),
            'private ingredients',
        );
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

    private function subscriberForWorkspace(Workspace $workspace): User
    {
        $subscriber = $workspace->relationLoaded('owner')
            ? $workspace->owner
            : $workspace->owner()->first();

        if (! $subscriber instanceof User) {
            throw new RuntimeException("Workspace {$workspace->id} has no subscriber owner.");
        }

        return $subscriber;
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

    /**
     * @param  array{used: int, limit: int|null, remaining: int|null, allowed: bool}  $usage
     */
    private function assertUsageAllows(array $usage, string $resource): void
    {
        if ($usage['allowed']) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => "Your current plan allows {$usage['limit']} {$resource}.",
        ]);
    }

    /**
     * @param  array<string, int|null>  $limits
     * @return array{used: int, limit: int|null, remaining: int|null, allowed: bool}
     */
    private function privateIngredientUsage(User $subscriber, ?Workspace $workspace, array $limits): array
    {
        return $this->usageLine(
            used: $this->privateIngredientCount($subscriber, $workspace),
            limit: $limits['private_ingredients'] ?? null,
        );
    }

    private function savedRecipeCount(User $subscriber, ?Workspace $workspace): int
    {
        return Recipe::withoutGlobalScopes()
            ->where(function ($query) use ($subscriber, $workspace): void {
                $query->where(function ($legacyRecipeQuery) use ($subscriber): void {
                    $legacyRecipeQuery
                        ->where('owner_type', OwnerType::User->value)
                        ->where('owner_id', $subscriber->id);
                });

                if ($workspace instanceof Workspace) {
                    $query->orWhere(function ($workspaceRecipeQuery) use ($workspace): void {
                        $workspaceRecipeQuery
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->where('owner_id', $workspace->id);
                    });
                }
            })
            ->whereNull('archived_at')
            ->count();
    }

    private function privateIngredientCount(User $subscriber, ?Workspace $workspace): int
    {
        return Ingredient::withoutGlobalScopes()
            ->where(function ($query) use ($subscriber, $workspace): void {
                $query->where(function ($legacyIngredientQuery) use ($subscriber): void {
                    $legacyIngredientQuery
                        ->where('owner_type', OwnerType::User->value)
                        ->where('owner_id', $subscriber->id);
                });

                if ($workspace instanceof Workspace) {
                    $query->orWhere(function ($workspaceIngredientQuery) use ($workspace): void {
                        $workspaceIngredientQuery
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->where('owner_id', $workspace->id);
                    });
                }
            })
            ->count();
    }

    private function companyWorkspaceFor(User $user): ?Workspace
    {
        return $user->company();
    }

    private function productionBatchCount(User $user): int
    {
        return ProductionBatch::query()
            ->where('user_id', $user->id)
            ->count();
    }
}
