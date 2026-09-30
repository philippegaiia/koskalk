<?php

use App\Enums\FormulaShareStatus;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\FormulaShare;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
});

it('reserves sharing to the actual selected workspace Owner and entitled Admin', function (string $role, bool $allowed): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $actor = $role === 'owner' ? $owner : User::factory()->create(['active_workspace_id' => $workspace->id, 'is_admin' => $role === 'platform_admin']);
    if (in_array($role, ['admin', 'editor', 'viewer'], true)) {
        WorkspaceMember::factory()->for($workspace)->for($actor, 'user')->create(['role' => WorkspaceMemberRole::from($role)]);
    }
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $share = FormulaShare::factory()->create(['source_workspace_id' => $workspace->id]);
    $incoming = FormulaShare::factory()->create(['recipient_workspace_id' => $workspace->id]);

    expect(Gate::forUser($actor)->allows('share', $recipe))->toBe($allowed)
        ->and(Gate::forUser($actor)->allows('view', $share))->toBe($allowed)
        ->and(Gate::forUser($actor)->allows('revoke', $share))->toBe($allowed)
        ->and(Gate::forUser($actor)->allows('accept', $incoming))->toBe($allowed)
        ->and(Gate::forUser($actor)->allows('decline', $incoming))->toBe($allowed);
})->with([['owner', true], ['admin', true], ['editor', false], ['viewer', false], ['nonmember', false], ['platform_admin', false]]);

it('requires live collaboration for Admin and keeps Owner authority without it', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $admin = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($admin, 'user')->create(['role' => WorkspaceMemberRole::Admin]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    expect(Gate::forUser($owner)->allows('share', $recipe))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('share', $recipe))->toBeFalse();
});

it('rechecks selected workspace and membership even with cached actor models', function (): void {
    $owner = User::factory()->create();
    $first = Workspace::factory()->for($owner, 'owner')->create();
    $second = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $first->id])->save();
    $recipe = Recipe::factory()->create(['workspace_id' => $first->id]);
    expect(Gate::forUser($owner)->allows('share', $recipe))->toBeTrue();
    User::query()->whereKey($owner->id)->update(['active_workspace_id' => $second->id]);

    expect(Gate::forUser($owner)->allows('share', $recipe))->toBeFalse();
});

it('separates sender and recipient transitions and closes expired or accepted grants', function (): void {
    $this->freezeTime();
    $source = Workspace::factory()->create();
    $recipient = Workspace::factory()->create();
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $recipient->id]);
    expect(Gate::forUser($source->owner)->allows('accept', $share))->toBeFalse()
        ->and(Gate::forUser($recipient->owner)->allows('revoke', $share))->toBeFalse();
    $share->forceFill(['expires_at' => now()])->save();
    expect(Gate::forUser($recipient->owner)->allows('accept', $share))->toBeFalse();
    $share->forceFill(['status' => FormulaShareStatus::Accepted])->save();
    expect(Gate::forUser($source->owner)->allows('revoke', $share))->toBeFalse();
});

it('denies a cached Admin after membership removal and hides grants from another selected workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $admin = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($admin, 'user')->create(['role' => WorkspaceMemberRole::Admin]);
    $share = FormulaShare::factory()->create(['recipient_workspace_id' => $workspace->id]);
    expect(Gate::forUser($admin)->allows('view', $share))->toBeTrue();
    $membership->delete();

    expect(Gate::forUser($admin)->allows('view', $share))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('accept', $share))->toBeFalse()
        ->and(Gate::forUser(Workspace::factory()->create()->owner)->allows('view', $share))->toBeFalse();
});
