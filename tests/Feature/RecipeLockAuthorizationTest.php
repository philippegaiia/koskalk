<?php

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\RecipesIndex;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('limits formula lock management to actual owners and workspace admins', function (WorkspaceMemberRole $role, bool $allowed): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $actor = $role === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create();
    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }

    expect($actor->can('manageLock', $recipe))->toBe($allowed);
    foreach ([false, true] as $locked) {
        $html = view('livewire.dashboard.partials.recipe-workbench.header', ['workbench' => ['recipe' => [
            'public_id' => $recipe->public_id,
            'is_locked' => $locked,
            'can_manage_lock' => $actor->can('manageLock', $recipe),
        ]]])->render();
        expect(str_contains($html, route($locked ? 'recipes.unlock' : 'recipes.lock', $recipe)))->toBe($allowed);
    }
})->with([
    [WorkspaceMemberRole::Owner, true],
    [WorkspaceMemberRole::Admin, true],
    [WorkspaceMemberRole::Editor, false],
    [WorkspaceMemberRole::Viewer, false],
]);

it('does not grant lock management to application admins or a false owner membership', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $actor = User::factory()->create(['is_admin' => true]);
    WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Owner]);

    expect($actor->can('manageLock', $recipe))->toBeFalse();
});

it('preserves personal formula owner lock authority', function (): void {
    $owner = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $owner->id, 'workspace_id' => null]);

    expect($owner->can('manageLock', $recipe))->toBeTrue()
        ->and(User::factory()->create()->can('manageLock', $recipe))->toBeFalse();
});

it('rejects lock endpoints even when an editor has general recipe access', function (bool $locked): void {
    $workspace = Workspace::factory()->create();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'locked_at' => $locked ? now() : null,
        'locked_by' => $locked ? $workspace->owner_user_id : null,
    ]);
    $before = $recipe->only(['locked_at', 'locked_by']);
    Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, ['view', 'update'], true) ? true : null);

    $this->actingAs($editor)->post(route($locked ? 'recipes.unlock' : 'recipes.lock', $recipe))->assertForbidden();
    expect($recipe->fresh()->only(['locked_at', 'locked_by']))->toEqual($before);
})->with([false, true]);

it('groups listing lock authorization by workspace and hides denied controls', function (): void {
    $workspace = Workspace::factory()->create();
    $recipes = Recipe::factory()->count(3)->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $permissionChecks = [];
    Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use (&$permissionChecks): void {
        if ($ability === 'manageLock') {
            $permissionChecks[] = $arguments[0]->workspace_id;
        }
    });
    $component = Livewire::actingAs($workspace->owner)->test(RecipesIndex::class);
    foreach ($recipes as $recipe) {
        $component->assertSee(route('recipes.lock', $recipe), false);
    }
    expect($permissionChecks)->toBe([$workspace->id]);

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'manageLock' ? false : null);
    $component->call('$refresh');
    foreach ($recipes as $recipe) {
        $component->assertDontSee(route('recipes.lock', $recipe), false);
    }
});
