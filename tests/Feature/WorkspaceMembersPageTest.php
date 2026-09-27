<?php

use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\SettingsIndex;
use App\Livewire\Dashboard\WorkspaceMembers;
use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceMemberInvitation;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

afterEach(function (): void {
    Cache::forget(InterfaceTranslation::getCacheKey('workspaces', 'fr'));
});

function membersPageWorkspace(bool $collaboration = true): Workspace
{
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => $collaboration]);
    UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    PlanLimit::factory()->for($plan)->create(['key' => 'workspace_members', 'value' => 5]);

    return $workspace;
}

it('renders the actual owner without a membership row and initializes current member roles and seat usage', function (): void {
    $workspace = membersPageWorkspace();
    $member = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Viewer]);
    $this->actingAs($workspace->owner);

    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->assertOk()
        ->assertSee($workspace->owner->email)
        ->assertSee($member->user->email)
        ->assertSet('memberRoles.'.$member->id, 'viewer')
        ->assertViewHas('seatUsage', fn (array $usage): bool => $usage['members'] === 2 && $usage['limit'] === 5)
        ->assertViewHas('roleOptions', [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]);
});

it('uses the current database locale override on the team members page', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $workspace = membersPageWorkspace();
    $workspace->owner->forceFill(['locale' => 'fr'])->save();
    InterfaceTranslation::query()->create([
        'group' => 'workspaces',
        'key' => 'members.heading',
        'text' => ['fr' => 'Membres de l’équipe'],
    ]);
    app()->setLocale('fr');
    $this->actingAs($workspace->owner);

    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->assertOk()
        ->assertSeeText('Membres de l’équipe')
        ->assertDontSeeText('Team members');
});

it('hides admin invitation details and actions from admins and denies a forged admin invitation', function (): void {
    Notification::fake();
    $workspace = membersPageWorkspace();
    $admin = User::factory()->create();
    $adminMember = WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    WorkspaceInvitation::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Admin, 'email' => 'private-admin@example.test']);
    $this->actingAs($admin);

    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->assertDontSee('private-admin@example.test')
        ->assertViewHas('editableMemberIds', fn (array $ids): bool => ! in_array($adminMember->id, $ids, true))
        ->assertViewHas('roleOptions', [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer])
        ->set('invitationEmail', 'forged-admin@example.test')
        ->set('invitationRole', 'admin')
        ->call('invite')
        ->assertForbidden();
    Notification::assertNothingSent();
});

it('forbids nonmanaging roles from mounting member management', function (WorkspaceMemberRole $role): void {
    $workspace = membersPageWorkspace();
    $user = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => $role]);
    $this->actingAs($user);
    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])->assertForbidden();
})->with([WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]);

it('hides the settings entry and rejects mounting when the rollout flag is disabled', function (): void {
    $workspace = membersPageWorkspace();
    $this->actingAs($workspace->owner);
    config(['workspaces.collaboration_enabled' => false]);
    Livewire::test(SettingsIndex::class)->assertViewHas('canManageMembers', false);
    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])->assertNotFound();
});

it('shows owner eligibility without leaking member or invitation emails on a noncollaborative plan', function (): void {
    $workspace = membersPageWorkspace(false);
    $member = WorkspaceMember::factory()->for($workspace)->create();
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'hidden-invite@example.test']);
    $this->actingAs($workspace->owner);
    Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->assertOk()
        ->assertViewHas('collaborationAvailable', false)
        ->assertViewHas('members', null)
        ->assertDontSee($member->user->email)
        ->assertDontSee('hidden-invite@example.test');
});

it('rejects stale member actions after selected company changes or manager demotion', function (string $change): void {
    $workspace = membersPageWorkspace();
    $admin = User::factory()->create();
    $adminMember = WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    $target = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Viewer]);
    $this->actingAs($admin);
    $page = Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id]);
    if ($change === 'selection') {
        $other = Workspace::factory()->for($admin, 'owner')->create();
        $admin->forceFill(['active_workspace_id' => $other->id])->save();
    } else {
        $adminMember->update(['role' => WorkspaceMemberRole::Viewer]);
    }
    $page->call('removeMember', $target->id)->assertForbidden();
    expect($target->fresh())->not->toBeNull();
})->with(['selection', 'demotion']);

it('invites resends expired invitations and revokes through the member component', function (): void {
    Notification::fake();
    $workspace = membersPageWorkspace();
    $this->actingAs($workspace->owner);
    $page = Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->set('invitationEmail', 'new-colleague@example.test')
        ->set('invitationRole', 'editor')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSee('new-colleague@example.test');
    $invitation = WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->sole();
    expect($invitation->role)->toBe(WorkspaceMemberRole::Editor);
    $invitation->update(['expires_at' => now()->subDay(), 'last_sent_at' => now()->subDays(8)]);
    $page->call('resendInvitation', $invitation->id)->assertHasNoErrors();
    Notification::assertSentOnDemandTimes(WorkspaceMemberInvitation::class, 2);
    $page->call('revokeInvitation', $invitation->id)->assertHasNoErrors()->assertDontSee('new-colleague@example.test');
    expect($invitation->fresh()->revoked_at)->not->toBeNull();
});

it('persists member role changes and removal through the member component', function (): void {
    $workspace = membersPageWorkspace();
    $member = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceMemberRole::Editor]);
    $this->actingAs($workspace->owner);
    $page = Livewire::test(WorkspaceMembers::class, ['workspaceId' => $workspace->id])
        ->set('memberRoles.'.$member->id, 'viewer')
        ->call('updateMemberRole', $member->id)
        ->assertHasNoErrors();
    expect($member->fresh()->role)->toBe(WorkspaceMemberRole::Viewer);
    $page->call('removeMember', $member->id)->assertHasNoErrors()->assertDontSee($member->user->email);
    expect($member->fresh())->toBeNull();
});

it('renders the invitation acceptance branch appropriate to the current identity', function (string $identity): void {
    $workspace = membersPageWorkspace();
    $token = bin2hex(random_bytes(32));
    WorkspaceInvitation::factory()->for($workspace)->create([
        'email' => 'recipient@example.test', 'token_hash' => hash('sha256', $token),
    ]);
    if ($identity !== 'new') {
        $recipient = User::factory()->create(['email' => 'recipient@example.test', 'email_verified_at' => $identity === 'unverified' ? null : now()]);
        if ($identity !== 'existing-guest') {
            $this->actingAs($identity === 'wrong' ? User::factory()->create() : $recipient);
        }
    }
    $response = $this->get(route('workspace-invitations.show', $token))->assertOk()->assertSee($workspace->name)->assertSee('recipient@example.test');
    match ($identity) {
        'new' => $response->assertSee('name="password_confirmation"', false)->assertSee('readonly', false),
        'existing-guest' => $response->assertSee('Sign in with the invited email address')->assertDontSee('name="password"', false),
        'verified' => $response->assertSee('Accept invitation')->assertDontSee('name="password"', false),
        'wrong' => $response->assertSee('different email address')->assertDontSee('Accept invitation'),
        'unverified' => $response->assertSee('Verify email address')->assertDontSee('Accept invitation'),
    };
})->with(['new', 'existing-guest', 'verified', 'wrong', 'unverified']);
