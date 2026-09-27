<?php

use App\Enums\WorkspaceMemberRole;
use App\Listeners\CreateDefaultCompany;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceMemberInvitation;
use App\Services\WorkspaceInvitationService;
use App\Services\WorkspaceMembershipService;
use Filament\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

function invitationWorkspace(?int $limit = 3): Workspace
{
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    PlanLimit::factory()->for($plan)->create(['key' => 'workspace_members', 'value' => $limit]);

    return $workspace;
}

/** @return array{WorkspaceInvitation, string} */
function pendingWorkspaceInvitation(Workspace $workspace, string $email = 'invited@example.test', WorkspaceMemberRole $role = WorkspaceMemberRole::Editor): array
{
    $token = bin2hex(random_bytes(32));
    $invitation = WorkspaceInvitation::factory()->for($workspace)->create([
        'invited_by_user_id' => $workspace->owner_user_id, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $token),
    ]);

    return [$invitation, $token];
}

it('reserves the final seat with a normalized email and sends only after the transaction commits', function (): void {
    Notification::fake();
    $workspace = invitationWorkspace(2);
    $invitation = DB::transaction(function () use ($workspace): WorkspaceInvitation {
        $invitation = app(WorkspaceInvitationService::class)->issue($workspace->owner, $workspace, ' INVITED@Example.test ', WorkspaceMemberRole::Editor);
        Notification::assertNothingSent();

        return $invitation;
    });
    Notification::assertSentOnDemand(WorkspaceMemberInvitation::class, function (WorkspaceMemberInvitation $notification, array $channels, object $notifiable) use ($invitation): bool {
        return $notifiable->routes['mail'] === 'invited@example.test' && hash('sha256', $notification->token) === $invitation->token_hash;
    });
    expect($invitation->toArray())->not->toHaveKey('token_hash');
    expect(app(WorkspaceMembershipService::class)->seatUsage($workspace))->toMatchArray(['members' => 1, 'pending' => 1, 'used' => 2, 'remaining' => 0]);
    expect(fn () => app(WorkspaceInvitationService::class)->issue($workspace->owner, $workspace, 'another@example.test', WorkspaceMemberRole::Viewer))->toThrow(ValidationException::class);
});

it('creates a verified member through a token without owner provisioning or entitlements', function (): void {
    $workspace = invitationWorkspace();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    $this->post(route('workspace-invitations.accept', $token), ['name' => 'Invited Person', 'password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!', 'is_admin' => true, 'role' => 'owner'])->assertRedirect(route('dashboard'));
    $user = User::query()->where('email', $invitation->email)->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->active_workspace_id)->toBe($workspace->id)
        ->and($user->entitlements()->count())->toBe(0)
        ->and(Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->count())->toBe(0);
    expect(WorkspaceMember::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail()->role)->toBe(WorkspaceMemberRole::Editor);
    app(CreateDefaultCompany::class)->handle(new Registered($user));
    expect(Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->count())->toBe(0);
});

it('requires an existing account to sign in and preserves its credentials', function (): void {
    $workspace = invitationWorkspace();
    $user = User::factory()->create();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace, mb_strtolower($user->email));
    $hash = $user->password;
    $this->get(route('workspace-invitations.show', $token))->assertOk()->assertViewHas('requiresLogin', true)->assertSessionHas('url.intended', route('workspace-invitations.show', $token));
    $this->post(route('workspace-invitations.accept', $token), ['name' => 'Changed', 'password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!'])->assertSessionHasErrors('invitation');
    expect($user->fresh()->password)->toBe($hash);
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('requires the authenticated matching verified identity', function (bool $matching, bool $verified): void {
    $workspace = invitationWorkspace();
    $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
    [$invitation, $token] = pendingWorkspaceInvitation($workspace, $matching ? $user->email : 'another@example.test');
    $this->actingAs($user)->post(route('workspace-invitations.accept', $token))->assertForbidden();
    expect($invitation->fresh()->accepted_at)->toBeNull();
})->with([[false, true], [true, false]]);

it('accepts an existing member idempotently but never restores a removed membership with the old token', function (): void {
    $workspace = invitationWorkspace();
    $user = User::factory()->create();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace, $user->email);
    $this->actingAs($user)->post(route('workspace-invitations.accept', $token))->assertRedirect(route('dashboard'));
    $this->post(route('workspace-invitations.accept', $token))->assertRedirect(route('dashboard'));
    $member = WorkspaceMember::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
    app(WorkspaceMembershipService::class)->remove($workspace->owner, $member);
    $this->post(route('workspace-invitations.accept', $token))->assertNotFound();
    expect(WorkspaceMember::withoutGlobalScopes()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects unavailable tokens without creating users', function (string $state): void {
    $workspace = invitationWorkspace();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    $invitation->update($state === 'expired' ? ['expires_at' => now()->subSecond()] : ['revoked_at' => now()]);
    $this->post(route('workspace-invitations.accept', $token), ['name' => 'Member', 'password' => 'Secure-password-123!', 'password_confirmation' => 'Secure-password-123!'])->assertNotFound();
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
})->with(['expired', 'revoked']);

it('rejects acceptance after the inviter loses authority', function (string $change): void {
    $workspace = invitationWorkspace(5);
    $admin = User::factory()->create();
    $member = WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    $invitation->update(['invited_by_user_id' => $admin->id]);
    if ($change === 'removed') {
        $member->delete();
    } else {
        $member->update(['role' => WorkspaceMemberRole::Viewer]);
    }
    $user = User::factory()->create(['email' => $invitation->email]);
    $this->actingAs($user)->post(route('workspace-invitations.accept', $token))->assertForbidden();
})->with(['removed', 'demoted']);

it('denies acceptance when the owner collaboration feature or seat allowance is reduced', function (string $change): void {
    $workspace = invitationWorkspace(2);
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    if ($change === 'feature') {
        Plan::query()->update(['allows_collaboration' => false]);
    } else {
        PlanLimit::query()->where('key', 'workspace_members')->update(['value' => 1]);
    }
    $user = User::factory()->create(['email' => $invitation->email]);
    $response = $this->actingAs($user)->post(route('workspace-invitations.accept', $token));
    if ($change === 'feature') {
        $response->assertForbidden();
    } else {
        $response->assertSessionHasErrors('invitation');
    }
    expect($invitation->fresh()->accepted_at)->toBeNull();
})->with(['feature', 'seats']);

it('rotates expired invitation tokens on resend and releases the seat on revoke', function (): void {
    Notification::fake();
    $workspace = invitationWorkspace(2);
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    $invitation->update(['expires_at' => now()->subDay(), 'last_sent_at' => now()->subDays(8)]);
    $service = app(WorkspaceInvitationService::class);
    $service->resend($workspace->owner, $invitation);
    expect($service->findPending($token))->toBeNull();
    Notification::assertSentOnDemand(WorkspaceMemberInvitation::class, fn (WorkspaceMemberInvitation $notification): bool => $service->findPending($notification->token)?->id === $invitation->id);
    $service->revoke($workspace->owner, $invitation);
    expect(app(WorkspaceMembershipService::class)->seatUsage($workspace)['remaining'])->toBe(1);
});

it('keeps invitation endpoints closed when collaboration rollout is disabled', function (): void {
    $workspace = invitationWorkspace();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    config(['workspaces.collaboration_enabled' => false]);
    $this->get(route('workspace-invitations.show', $token))->assertNotFound();
    $this->post(route('workspace-invitations.accept', $token))->assertForbidden();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('preserves an invitation when its authorized inviter selects another company', function (): void {
    $workspace = invitationWorkspace();
    [$invitation, $token] = pendingWorkspaceInvitation($workspace);
    $other = Workspace::factory()->for($workspace->owner, 'owner')->create();
    $workspace->owner->forceFill(['active_workspace_id' => $other->id])->save();
    $recipient = User::factory()->create(['email' => $invitation->email]);
    $this->actingAs($recipient)->post(route('workspace-invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect($invitation->fresh()->accepted_by_user_id)->toBe($recipient->id);
});
