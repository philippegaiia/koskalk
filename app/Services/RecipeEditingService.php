<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class RecipeEditingService
{
    public const int LeaseSeconds = 90;

    /** @return array<string, mixed> */
    public function acquire(Recipe $recipe, User $user, string $token): array
    {
        $this->validateToken($token);

        return $this->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $token): array {
            $actor = $this->authorize($locked, $user);
            $lease = $this->lease($locked);
            if ($this->active($lease) && ! $this->owns($lease, $actor, $token)) {
                return $this->state($locked, $lease, 'blocked', $actor);
            }
            $this->writeLease($locked, $actor, $token);

            return $this->state($locked, $this->lease($locked), 'acquired', $actor);
        });
    }

    /** @return array<string, mixed> */
    public function heartbeat(Recipe $recipe, User $user, string $token): array
    {
        return $this->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $token): array {
            $actor = $this->authorize($locked, $user);
            $this->assertLease($locked, $actor, $token);
            $this->writeLease($locked, $actor, $token);

            return $this->state($locked, $this->lease($locked), 'acquired', $actor);
        });
    }

    public function release(Recipe $recipe, User $user, string $token): void
    {
        $this->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $token): void {
            $actor = $this->authorize($locked, $user);
            if ($this->owns($this->lease($locked), $actor, $token)) {
                DB::table('recipe_edit_leases')->where('recipe_id', $locked->id)->delete();
            }
        });
    }

    /** @return array<string, mixed> */
    public function takeover(Recipe $recipe, User $user, string $token, string $reason): array
    {
        $this->validateToken($token);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();

        return $this->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $token, $reason): array {
            $actor = $this->authorize($locked, $user, 'manageLock');
            $previous = $this->lease($locked);
            DB::table('recipe_edit_takeovers')->insert([
                'recipe_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'previous_user_id' => $previous?->user_id,
                'actor_name' => $actor->name,
                'previous_holder_name' => $previous?->holder_name,
                'reason' => trim($reason),
                'created_at' => now(),
            ]);
            $this->writeLease($locked, $actor, $token);

            return $this->state($locked, $this->lease($locked), 'acquired', $actor);
        });
    }

    /** @return array<string, mixed> */
    public function status(Recipe $recipe, User $user, ?string $token = null): array
    {
        $fresh = Recipe::withoutGlobalScopes()->findOrFail($recipe->id);
        $actor = $this->authorize($fresh, $user, 'view');
        $lease = $this->lease($fresh);

        return $this->state($fresh, $lease, ! $this->active($lease) ? 'available' : ($token !== null && $this->owns($lease, $actor, $token) ? 'acquired' : 'blocked'), $actor);
    }

    /** Serialize missing-lease creation on the existing parent, never on an absent lease row. */
    public function withLockedRecipe(Recipe $recipe, Closure $callback): mixed
    {
        return DB::transaction(function () use ($recipe, $callback): mixed {
            $workspaceId = Recipe::withoutGlobalScopes()->whereKey($recipe->id)->value('workspace_id');
            if ($workspaceId !== null) {
                app(WorkspaceWriteLock::class)->acquire((int) $workspaceId);
            }
            $locked = Recipe::withoutGlobalScopes()->lockForUpdate()->findOrFail($recipe->id);
            if ($locked->workspace_id !== $workspaceId) {
                throw new AuthorizationException;
            }

            return $callback($locked);
        }, attempts: 5);
    }

    public function authorize(Recipe $recipe, User $user, string $ability = 'update'): User
    {
        $actor = User::withoutGlobalScopes()->findOrFail($user->id);
        if ($recipe->workspace_id !== null && (($actor->active_workspace_id !== null && $actor->active_workspace_id !== $recipe->workspace_id) || $actor->company()?->id !== $recipe->workspace_id)) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize($ability, $recipe);

        return $actor;
    }

    public function assertLease(Recipe $recipe, User $user, string $token): void
    {
        $lease = $this->lease($recipe);
        if (! $this->active($lease) || ! $this->owns($lease, $user, $token)) {
            throw ValidationException::withMessages(['editing_lease' => __('editing.validation.lease_ended')]);
        }
    }

    private function lease(Recipe $recipe): ?stdClass
    {
        return DB::table('recipe_edit_leases')->where('recipe_id', $recipe->id)->first();
    }

    private function active(?stdClass $lease): bool
    {
        return $lease !== null && Carbon::parse($lease->expires_at)->isFuture();
    }

    private function owns(?stdClass $lease, User $user, string $token): bool
    {
        return $lease !== null && (int) $lease->user_id === $user->id && hash_equals($lease->token_hash, hash('sha256', $token));
    }

    private function validateToken(string $token): void
    {
        if (! Str::isUuid($token)) {
            throw ValidationException::withMessages(['editing_lease' => __('editing.validation.token_required')]);
        }
    }

    private function writeLease(Recipe $recipe, User $user, string $token): void
    {
        DB::table('recipe_edit_leases')->updateOrInsert(['recipe_id' => $recipe->id], [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'holder_name' => $user->name,
            'expires_at' => now()->addSeconds(self::LeaseSeconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{recipe_revision: int, current_version_id: ?int, costing_revision: int} */
    public function revisions(Recipe $recipe): array
    {
        $version = RecipeVersion::withoutGlobalScopes()
            ->leftJoin('recipe_version_costings', 'recipe_version_costings.recipe_version_id', '=', 'recipe_versions.id')
            ->where('recipe_versions.recipe_id', $recipe->id)
            ->where('recipe_versions.is_current', true)
            ->select(['recipe_versions.id', 'recipe_version_costings.edit_revision as costing_revision'])
            ->first();

        return [
            'recipe_revision' => (int) $recipe->edit_revision,
            'current_version_id' => $version?->id,
            'costing_revision' => (int) ($version?->costing_revision ?? 0),
        ];
    }

    /** @return array{status: string, release_url: string, is_locked: bool, can_take_over: bool, holder_name: ?string, expires_at: ?string, recipe_revision: int, current_version_id: ?int, costing_revision: int} */
    private function state(Recipe $recipe, ?stdClass $lease, string $status, User $user): array
    {

        return [
            'status' => $status,
            'release_url' => route('recipes.editing.release', $recipe->public_id),
            'is_locked' => $recipe->locked_at !== null,
            'can_take_over' => $user->can('manageLock', $recipe),
            'holder_name' => $this->active($lease) ? $lease->holder_name : null,
            'expires_at' => $this->active($lease) ? Carbon::parse($lease->expires_at)->toIso8601String() : null,
            ...$this->revisions($recipe),
        ];
    }
}
