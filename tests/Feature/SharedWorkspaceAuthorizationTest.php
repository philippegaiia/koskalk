<?php

use App\Enums\OwnerType;
use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\WorkspaceMemberRole;
use App\Enums\WorkspaceModule;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\WorkspaceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies workspace recipe abilities consistently to discovery and record access', function (WorkspaceMemberRole $role, bool $write, bool $manage): void {
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    $actor = $role === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create();
    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $other = Recipe::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($actor);

    expect(Recipe::query()->pluck('id')->all())->toBe([$recipe->id]);
    expect($actor->can('view', $recipe))->toBeTrue()
        ->and($actor->can('create', Recipe::class))->toBe($write)
        ->and($actor->can('update', $recipe))->toBe($write)
        ->and($actor->can('delete', $recipe))->toBe($manage)
        ->and($actor->can('manageLock', $recipe))->toBe($manage)
        ->and($actor->can('view', $other))->toBeFalse();
})->with([
    'owner' => [WorkspaceMemberRole::Owner, true, true],
    'admin' => [WorkspaceMemberRole::Admin, true, true],
    'editor' => [WorkspaceMemberRole::Editor, true, false],
    'viewer' => [WorkspaceMemberRole::Viewer, false, false],
]);

it('rejects members when the owner collaboration grant expires despite a personal member grant', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => WorkspaceMemberRole::Admin]);
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $entitlement = UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    UserEntitlement::factory()->for($member)->for($plan)->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    expect($member->can('update', $recipe))->toBeTrue();

    $entitlement->update(['ends_at' => now()->subMinute()]);

    expect($member->can('view', $recipe))->toBeFalse()
        ->and($workspace->owner->can('view', $recipe))->toBeTrue();
    $this->actingAs($member);
    expect(Recipe::query()->exists())->toBeFalse();
});

it('rechecks membership and selection after authorization on the same model instances', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => WorkspaceMemberRole::Editor]);
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    expect($member->can('update', $recipe))->toBeTrue();
    $member->accessibleWorkspaceIds();
    $membership->update(['role' => WorkspaceMemberRole::Viewer]);
    expect($member->can('update', $recipe))->toBeFalse()->and($member->can('view', $recipe))->toBeTrue();

    $otherWorkspace = Workspace::factory()->for($member, 'owner')->create();
    User::query()->whereKey($member->id)->update(['active_workspace_id' => $otherWorkspace->id]);
    expect($member->can('view', $recipe))->toBeFalse();

    User::query()->whereKey($member->id)->update(['active_workspace_id' => $workspace->id]);
    $membership->delete();
    expect($member->can('view', $recipe))->toBeFalse();
});

it('does not grant workspace access to platform admins or forged owner memberships', function (bool $forgedMembership): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = User::factory()->create(['is_admin' => true]);
    if ($forgedMembership) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Owner]);
    }
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($actor);

    expect($actor->can('view', $recipe))->toBeFalse()
        ->and($actor->can('manageLock', $recipe))->toBeFalse()
        ->and(Recipe::query()->exists())->toBeFalse();
})->with([false, true]);

it('preserves personal records without leaking actor-attributed foreign workspace records', function (): void {
    $actor = User::factory()->create();
    $personal = Recipe::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $actor->id]);
    $foreign = Recipe::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $actor->id, 'workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($actor);

    expect(Recipe::query()->pluck('id')->all())->toBe([$personal->id]);
    expect($actor->can('view', $personal))->toBeTrue()->and($actor->can('view', $foreign))->toBeFalse();
});

it('rejects inconsistent nested formula workspace identifiers', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->for($recipe)->create(['workspace_id' => Workspace::factory()->create()->id]);

    expect($workspace->owner->can('view', $version))->toBeFalse();
});

it('keeps module eligibility separate from shared workspace membership', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $authorization = app(WorkspaceAuthorization::class);

    expect($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::Formulation))->toBeTrue()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductionBench))->toBeFalse();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create(['status' => ProductionBenchEntitlementStatus::Cancelled]);
    expect($authorization->canViewModule($owner, $workspace->id, WorkspaceModule::ProductionBench))->toBeTrue()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductionBench))->toBeFalse();
});

it('checks nested formula permissions against the parent rather than child attribution', function (string $modelClass): void {
    $workspace = Workspace::factory()->create();
    $actor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Viewer]);
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->for($recipe)->create(['workspace_id' => $workspace->id]);
    $child = $modelClass::factory()->for($version, 'recipeVersion')->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::User, 'owner_id' => $actor->id]);

    expect($actor->can('view', $child))->toBeTrue()
        ->and($actor->can('update', $child))->toBeFalse()
        ->and($actor->can('delete', $child))->toBeFalse();

    $child->update(['workspace_id' => null]);

    expect($actor->can('view', $child))->toBeFalse();
})->with(['item' => [RecipeItem::class], 'phase' => [RecipePhase::class]]);

it('does not permit admins to manage another admin membership', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Admin]);
    $target = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Admin]);

    expect($actor->can('update', $target))->toBeFalse()
        ->and($actor->can('delete', $target))->toBeFalse();
});

it('rejects the owner role in ordinary membership creation and changes', function (): void {
    $role = WorkspaceMemberRole::Owner;
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $target = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Viewer]);

    expect($workspace->owner->can('create', [WorkspaceMember::class, $role]))->toBeFalse()
        ->and($workspace->owner->can('update', [$target, $role]))->toBeFalse();
});

it('allows only actual owners to appoint and manage workspace admins', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $admin = User::factory()->create();
    $adminMembership = WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    $editorMembership = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Editor]);

    expect($workspace->owner->can('create', [WorkspaceMember::class, WorkspaceMemberRole::Admin]))->toBeTrue()
        ->and($workspace->owner->can('update', [$editorMembership, WorkspaceMemberRole::Admin]))->toBeTrue()
        ->and($workspace->owner->can('delete', $adminMembership))->toBeTrue()
        ->and($admin->can('create', [WorkspaceMember::class, WorkspaceMemberRole::Admin]))->toBeFalse()
        ->and($admin->can('update', [$editorMembership, WorkspaceMemberRole::Admin]))->toBeFalse();
});
