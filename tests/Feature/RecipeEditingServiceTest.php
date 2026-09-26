<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\RecipeEditingService;
use App\Services\RecipeMutationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('does not reserve on reading and refuses a second tab for the same owner', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();

    expect($editing->status($recipe, $workspace->owner)['status'])->toBe('available');
    $this->assertDatabaseCount('recipe_edit_leases', 0);
    expect($editing->acquire($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
    expect($editing->acquire($recipe, $workspace->owner, (string) Str::uuid())['status'])->toBe('blocked');
    $editing->release($recipe, $workspace->owner, (string) Str::uuid());
    expect($editing->status($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
    $editing->release($recipe, $workspace->owner, $token);
    $this->assertDatabaseCount('recipe_edit_leases', 0);
});

it('renews only a live matching lease and never resurrects an expired heartbeat', function (): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);
    $this->travel(80)->seconds();
    expect($editing->heartbeat($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
    $this->travel(90)->seconds();

    expect(fn () => $editing->heartbeat($recipe, $workspace->owner, $token))->toThrow(ValidationException::class);
    expect($editing->acquire($recipe, $workspace->owner, (string) Str::uuid())['status'])->toBe('acquired');
});

it('audits explicit owner or admin takeover and invalidates the previous token', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $admin = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);

    $editing->takeover($recipe, $admin, (string) Str::uuid(), 'Owner disconnected');

    expect(fn () => $editing->heartbeat($recipe, $workspace->owner, $token))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('recipe_edit_takeovers', ['actor_user_id' => $admin->id, 'previous_user_id' => $workspace->owner_user_id, 'reason' => 'Owner disconnected']);
    expect(fn () => $editing->acquire($recipe, $admin, (string) Str::uuid()))->toThrow(AuthorizationException::class);
});

it('checks fresh selection and privilege on every request', function (string $change): void {
    $workspace = Workspace::factory()->create();
    $user = $workspace->owner;
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $user, $token);
    if ($change === 'switch') {
        $other = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $user->forceFill(['active_workspace_id' => $other->id])->save();
    } else {
        $workspace->update(['owner_user_id' => User::factory()->create()->id]);
    }

    expect(fn () => $editing->heartbeat($recipe, $user, $token))->toThrow(AuthorizationException::class);
})->with(['switch', 'revoke']);

it('atomically rejects stale recipe and costing revisions and preserves data', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'is_current' => true]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $workspace->owner_user_id, 'currency' => 'EUR', 'oil_unit_for_costing' => 'g']);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $workspace->owner, $token);
    $guard = app(RecipeMutationGuard::class);
    $guard->run($recipe, $workspace->owner, $token, 0, $version->id, function (Recipe $locked): void {
        $locked->update(['name' => 'Saved']);
    }, 0);

    expect(fn () => $guard->run($recipe, $workspace->owner, $token, 0, $version->id, fn (Recipe $locked) => $locked->update(['name' => 'Stale'])))->toThrow(ValidationException::class);
    expect(fn () => $guard->run($recipe, $workspace->owner, $token, 1, $version->id, fn (Recipe $locked) => $locked->update(['name' => 'Stale']), 0))->toThrow(ValidationException::class);
    expect($recipe->fresh()->name)->toBe('Saved');
    expect((int) $recipe->fresh()->edit_revision)->toBe(1);
    expect((int) $costing->fresh()->edit_revision)->toBe(1);
});

it('rolls back a request whose lease expires before its callback finishes', function (): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Original']);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $workspace->owner, $token);

    expect(fn () => app(RecipeMutationGuard::class)->run($recipe, $workspace->owner, $token, 0, null, function (Recipe $locked): void {
        $locked->update(['name' => 'Too late']);
        $this->travel(90)->seconds();
    }))->toThrow(ValidationException::class);
    expect($recipe->fresh()->name)->toBe('Original');
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
});

it('rejects replacement current versions and handles absent costing revision zero', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'is_current' => true]);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $workspace->owner, $token);
    $guard = app(RecipeMutationGuard::class);

    expect(fn () => $guard->run($recipe, $workspace->owner, $token, 0, null, fn () => null))->toThrow(ValidationException::class);
    $guard->run($recipe, $workspace->owner, $token, 0, $version->id, fn () => RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $workspace->owner_user_id, 'currency' => 'EUR', 'oil_unit_for_costing' => 'g']), 0);
    $this->assertDatabaseHas('recipe_version_costings', ['recipe_version_id' => $version->id, 'edit_revision' => 1]);
});

it('keeps permanent formula locks separate from permitted costing writes', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'locked_at' => now()]);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $workspace->owner, $token);
    $guard = app(RecipeMutationGuard::class);

    expect(fn () => $guard->run($recipe, $workspace->owner, $token, 0, null, fn () => null))->toThrow(ValidationException::class);
    $guard->run($recipe, $workspace->owner, $token, 0, null, fn () => null, allowLocked: true);
    expect((int) $recipe->fresh()->edit_revision)->toBe(1);
});
