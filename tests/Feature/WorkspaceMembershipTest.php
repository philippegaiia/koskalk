<?php

use App\Enums\WorkspaceMemberRole;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

function memberManagementWorkspace(): Workspace
{
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    PlanLimit::factory()->for($plan)->create(['key' => 'workspace_members', 'value' => 10]);

    return $workspace;
}

it('allows only the owner to invite admins and admins to invite editor or viewer roles', function (WorkspaceMemberRole $actorRole, WorkspaceMemberRole $targetRole, bool $allowed): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    $actor = $actorRole === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create();
    if ($actorRole !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $actorRole]);
    }
    $invite = fn () => app(WorkspaceInvitationService::class)->issue($actor, $workspace, 'recipient@example.test', $targetRole);
    if ($allowed) {
        expect($invite()->role)->toBe($targetRole);
        Notification::assertSentOnDemand(WorkspaceMemberInvitation::class);
    } else {
        expect($invite)->toThrow(AuthorizationException::class);
        Notification::assertNothingSent();
    }
})->with([
    [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin, true],
    [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Owner, false],
    [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, true],
    [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Viewer, true],
    [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Admin, false],
    [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer, false],
    [WorkspaceMemberRole::Viewer, WorkspaceMemberRole::Viewer, false],
]);

it('protects the actual owner and admins from unauthorized role changes and removals', function (string $target, string $operation): void {
    $workspace = memberManagementWorkspace();
    $actor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Admin]);
    $member = WorkspaceMember::factory()->for($workspace)->create([
        'user_id' => $target === 'owner' ? $workspace->owner_user_id : User::factory(),
        'role' => $target === 'owner' ? WorkspaceMemberRole::Viewer : WorkspaceMemberRole::Admin,
    ]);
    $service = app(WorkspaceMembershipService::class);
    expect(fn () => $operation === 'remove' ? $service->remove($actor, $member) : $service->updateRole($actor, $member, WorkspaceMemberRole::Viewer))->toThrow(AuthorizationException::class);
    expect($member->fresh())->not->toBeNull();
})->with([['owner', 'remove'], ['owner', 'update'], ['admin', 'remove'], ['admin', 'update']]);

it('updates editor roles and removes members with a safe selected company fallback', function (): void {
    $workspace = memberManagementWorkspace();
    $user = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $ownCompany = Workspace::factory()->for($user, 'owner')->create();
    $member = WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Editor]);
    $service = app(WorkspaceMembershipService::class);
    $service->updateRole($workspace->owner, $member, WorkspaceMemberRole::Viewer);
    expect($member->fresh()->role)->toBe(WorkspaceMemberRole::Viewer);
    $service->remove($workspace->owner, $member);
    expect($member->fresh())->toBeNull();
    expect($user->fresh()->active_workspace_id)->toBe($ownCompany->id);
});

it('counts the owner once and excludes pending invitations for existing members', function (): void {
    $workspace = memberManagementWorkspace();
    WorkspaceMember::factory()->for($workspace)->for($workspace->owner)->create(['role' => WorkspaceMemberRole::Owner]);
    $user = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => WorkspaceMemberRole::Viewer]);
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => $user->email]);
    WorkspaceInvitation::factory()->for($workspace)->create(['expires_at' => now()->subDay()]);
    WorkspaceInvitation::factory()->for($workspace)->create(['revoked_at' => now()]);
    WorkspaceInvitation::factory()->for($workspace)->create(['accepted_at' => now()]);
    WorkspaceInvitation::factory()->for($workspace)->create();
    expect(app(WorkspaceMembershipService::class)->seatUsage($workspace))->toBe(['members' => 2, 'pending' => 1, 'used' => 3, 'limit' => 10, 'remaining' => 7]);
});

it('denies extra seats for missing limits but respects explicit unlimited seats', function (bool $unlimited): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    if ($unlimited) {
        PlanLimit::query()->update(['value' => null]);
        app(WorkspaceInvitationService::class)->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor);
        Notification::assertSentOnDemand(WorkspaceMemberInvitation::class);
    } else {
        PlanLimit::query()->delete();
        expect(fn () => app(WorkspaceInvitationService::class)->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor))->toThrow(ValidationException::class);
        Notification::assertNothingSent();
    }
})->with([false, true]);

it('denies forged cross-company actions and platform administrator bypasses', function (): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    $actor = User::factory()->create(['is_admin' => true]);
    Workspace::factory()->for($actor, 'owner')->create();
    expect(fn () => app(WorkspaceInvitationService::class)->issue($actor, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor))->toThrow(HttpException::class);
    Notification::assertNothingSent();
});

it('rejects existing member invitations and duplicate pending invitations without reserving more seats', function (): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    $service = app(WorkspaceInvitationService::class);
    expect(fn () => $service->issue($workspace->owner, $workspace, $workspace->owner->email, WorkspaceMemberRole::Editor))->toThrow(ValidationException::class);
    $service->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor);
    $this->travel(61)->seconds();
    expect(fn () => $service->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Viewer))->toThrow(ValidationException::class);
    expect(app(WorkspaceMembershipService::class)->seatUsage($workspace)['pending'])->toBe(1);
    Notification::assertSentOnDemandTimes(WorkspaceMemberInvitation::class, 1);
});

it('bounds pending invitations and applies recipient cooldowns', function (): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    $service = app(WorkspaceInvitationService::class);
    $invitation = $service->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor);
    expect(fn () => $service->resend($workspace->owner, $invitation))->toThrow(ValidationException::class);
    config(['workspaces.maximum_pending_invitations' => 1]);
    expect(fn () => $service->issue($workspace->owner, $workspace, 'second@example.test', WorkspaceMemberRole::Editor))->toThrow(ValidationException::class);
    Notification::assertSentOnDemandTimes(WorkspaceMemberInvitation::class, 1);
});

it('limits invitation requests per actor and company', function (): void {
    Notification::fake();
    $workspace = memberManagementWorkspace();
    RateLimiter::increment('workspace-invitations:'.$workspace->id.':'.$workspace->owner_user_id, 60, 20);
    expect(fn () => app(WorkspaceInvitationService::class)->issue($workspace->owner, $workspace, 'recipient@example.test', WorkspaceMemberRole::Editor))->toThrow(ValidationException::class);
    expect(WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
    Notification::assertNothingSent();
});
