<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('does not confer owner authority through an ordinary membership', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => WorkspaceMemberRole::Owner]);

    expect($workspace->roleFor($member))->toBeNull();
    expect($member->workspaceRoleFor($workspace->id))->toBeNull();
    expect(Gate::forUser($member)->allows('update', $workspace))->toBeFalse();
});

it('rejects workspace access after a cached membership is revoked', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $member = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => WorkspaceMemberRole::Viewer]);
    expect($member->accessibleWorkspaceIds())->toContain($workspace->id);
    $membership->delete();

    expect(Gate::forUser($member)->allows('view', $workspace))->toBeFalse();
});

it('keeps management available to actual owners and admins only', function (WorkspaceMemberRole $role, bool $canManage): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = $role === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create();
    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }
    $otherMember = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Viewer]);

    expect(Gate::forUser($actor)->allows('view', $workspace))->toBeTrue();
    expect(Gate::forUser($actor)->allows('update', $workspace))->toBe($canManage);
    expect(Gate::forUser($actor)->allows('delete', $otherMember))->toBe($canManage);
})->with([
    'owner' => [WorkspaceMemberRole::Owner, true],
    'admin' => [WorkspaceMemberRole::Admin, true],
    'editor' => [WorkspaceMemberRole::Editor, false],
    'viewer' => [WorkspaceMemberRole::Viewer, false],
]);

it('protects the actual owner membership from changes even by an administrator', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $ownerMembership = WorkspaceMember::factory()->for($workspace)->for($workspace->owner)->create(['role' => WorkspaceMemberRole::Owner]);
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);

    expect(Gate::forUser($admin)->allows('update', $ownerMembership))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', $ownerMembership))->toBeFalse();
    expect(Gate::forUser($workspace->owner)->allows('delete', $ownerMembership))->toBeFalse();
});

it('resolves active workspace ownership from storage rather than a stale workspace instance', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $previousOwner = $workspace->owner;
    $previousOwner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $newOwner = User::factory()->create();
    Workspace::withoutGlobalScopes()->whereKey($workspace->id)->update(['owner_user_id' => $newOwner->id]);

    expect($workspace->roleFor($previousOwner))->toBeNull()
        ->and($previousOwner->company())->toBeNull()
        ->and($workspace->roleFor($newOwner))->toBe(WorkspaceMemberRole::Owner);
});
