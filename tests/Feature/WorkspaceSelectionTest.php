<?php

use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\SettingsIndex;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

function selectableMemberWorkspace(User $member, WorkspaceMemberRole $role = WorkspaceMemberRole::Viewer): Workspace
{
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);

    return $workspace;
}

it('lists and selects owned companies independently of plans and existing selection', function (): void {
    $user = User::factory()->create();
    $first = Workspace::factory()->for($user, 'owner')->create();
    $second = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $first->id])->save();
    $this->actingAs($user);
    $list = app(WorkspaceSelectionService::class)->accessibleWorkspaces($user);
    expect($list->pluck('id')->all())->toContain($first->id, $second->id);
    $this->post(route('workspace-selection.update'), ['workspace_public_id' => $second->public_id, 'return_url' => 'https://outside.example.test'])->assertRedirect(route('dashboard'));
    expect($user->fresh()->active_workspace_id)->toBe($second->id);
    expect(UserEntitlement::query()->count())->toBe(0);
    expect(Workspace::withoutGlobalScopes()->count())->toBe(2);
});

it('allows each genuine member role to select its collaborating company', function (WorkspaceMemberRole $role): void {
    $user = User::factory()->create();
    $workspace = selectableMemberWorkspace($user, $role);
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertRedirect(route('dashboard'));
    expect($user->fresh()->active_workspace_id)->toBe($workspace->id);
    expect(Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->exists())->toBeFalse();
    expect($user->entitlements()->exists())->toBeFalse();
})->with([WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]);

it('recovers from an inaccessible selected company by choosing another accessible company', function (): void {
    $user = User::factory()->create();
    $expired = selectableMemberWorkspace($user);
    UserEntitlement::query()->where('user_id', $expired->owner_user_id)->update(['ends_at' => now()->subDay()]);
    $available = selectableMemberWorkspace($user);
    $user->forceFill(['active_workspace_id' => $expired->id])->save();
    $list = app(WorkspaceSelectionService::class)->accessibleWorkspaces($user);
    expect($list->pluck('id')->all())->toBe([$available->id]);
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $available->public_id])->assertRedirect(route('dashboard'));
    expect($user->fresh()->active_workspace_id)->toBe($available->id);
});

it('denies foreign removed forged-owner and expired-collaboration targets without changing selection', function (string $access): void {
    $user = User::factory()->create(['is_admin' => true]);
    $owned = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $owned->id])->save();
    $target = selectableMemberWorkspace($user);
    $membership = WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $target->id)->where('user_id', $user->id)->firstOrFail();
    match ($access) {
        'foreign', 'removed' => $membership->delete(),
        'forged-owner' => $membership->update(['role' => WorkspaceMemberRole::Owner]),
        'expired' => UserEntitlement::query()->where('user_id', $target->owner_user_id)->update(['ends_at' => now()->subSecond()]),
    };
    expect(app(WorkspaceSelectionService::class)->accessibleWorkspaces($user)->pluck('id')->all())->toBe([$owned->id]);
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $target->public_id])->assertNotFound();
    expect($user->fresh()->active_workspace_id)->toBe($owned->id);
})->with(['removed', 'forged-owner', 'expired']);

it('uses the latest active owner entitlement without falling back to an older collaborative plan', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $workspace = selectableMemberWorkspace($user);
    UserEntitlement::query()->where('user_id', $workspace->owner_user_id)->update(['starts_at' => now()->subDays(2)]);
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => false]))->create(['starts_at' => now()->subDay()]);
    expect(app(WorkspaceSelectionService::class)->accessibleWorkspaces($user)->total())->toBe(0);
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertNotFound();
});

it('keeps one company selection idempotent and blocks disabled unauthenticated and unverified requests', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $this->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertRedirect(route('login'));
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertRedirect(route('dashboard'));
    $this->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertRedirect(route('dashboard'));
    expect(app(WorkspaceSelectionService::class)->accessibleWorkspaces($user)->total())->toBe(1);
    config(['workspaces.collaboration_enabled' => false]);
    $this->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertNotFound();
    config(['workspaces.collaboration_enabled' => true]);
    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $workspace->public_id])->assertRedirect(route('verification.notice'));
});

it('rejects malformed target identifiers through the request boundary', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => 'not-a-uuid'])->assertSessionHasErrors('workspace_public_id');
    expect($user->fresh()->active_workspace_id)->toBeNull();
});

it('paginates company discovery without an entitlement query for every company', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    UserEntitlement::factory()->for($owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $workspaces = Workspace::factory()->count(26)->for($owner, 'owner')->create();
    foreach ($workspaces as $workspace) {
        WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Viewer]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $page = app(WorkspaceSelectionService::class)->accessibleWorkspaces($user);
        expect($page->count())->toBe(25);
        expect($page->total())->toBe(26);
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
    } finally {
        DB::disableQueryLog();
    }
    expect(app(WorkspaceSelectionService::class)->accessibleWorkspaces($user, page: 2)->count())->toBe(1);
});

it('rejects a stale company settings form after a real company switch request', function (): void {
    $user = User::factory()->create();
    $first = Workspace::factory()->for($user, 'owner')->create(['name' => 'First company']);
    $second = Workspace::factory()->for($user, 'owner')->create(['name' => 'Second company']);
    $user->forceFill(['active_workspace_id' => $first->id])->save();
    $this->actingAs($user);
    $form = Livewire::test(SettingsIndex::class)->set('workspaceName', 'Stale replacement');
    $this->post(route('workspace-selection.update'), ['workspace_public_id' => $second->public_id])->assertRedirect(route('dashboard'));
    $form->call('saveWorkspace')->assertForbidden();
    expect($first->fresh()->name)->toBe('First company');
    expect($second->fresh()->name)->toBe('Second company');
});

it('searches literal wildcard characters without changing the selected company', function (): void {
    $user = User::factory()->create();
    $selected = Workspace::factory()->for($user, 'owner')->create(['name' => 'Selected']);
    $literal = Workspace::factory()->for($user, 'owner')->create(['name' => '100%_Pure! Soap']);
    Workspace::factory()->for($user, 'owner')->create(['name' => '100000Pure Soap']);
    $user->forceFill(['active_workspace_id' => $selected->id])->save();
    expect(app(WorkspaceSelectionService::class)->accessibleWorkspaces($user, '%_pure!')->pluck('id')->all())->toBe([$literal->id]);
    expect($user->fresh()->active_workspace_id)->toBe($selected->id);
});

it('returns not found for a deleted company without changing the current company', function (): void {
    $user = User::factory()->create();
    $current = Workspace::factory()->for($user, 'owner')->create();
    $deleted = Workspace::factory()->for($user, 'owner')->create();
    $publicId = $deleted->public_id;
    $deleted->delete();
    $user->forceFill(['active_workspace_id' => $current->id])->save();
    $this->actingAs($user)->post(route('workspace-selection.update'), ['workspace_public_id' => $publicId])->assertNotFound();
    expect($user->fresh()->active_workspace_id)->toBe($current->id);
});

it('validates malformed company picker query parameters', function (string $field): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('workspace-selection.index', [$field => ['unexpected']]))
        ->assertSessionHasErrors($field);
})->with(['search', 'workspace-selection-page']);
