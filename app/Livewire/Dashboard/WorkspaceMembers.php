<?php

namespace App\Livewire\Dashboard;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceCapabilities;
use App\Services\WorkspaceInvitationService;
use App\Services\WorkspaceMembershipService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class WorkspaceMembers extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $workspaceId = null;

    public string $invitationEmail = '';

    public string $invitationRole = WorkspaceMemberRole::Viewer->value;

    /** @var array<int, string> */
    public array $memberRoles = [];

    public int $membersPerPage = 25;

    public int $invitationsPerPage = 25;

    public string $statusMessage = '';

    public function mount(int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        $this->managerContext();
    }

    public function hydrate(): void
    {
        $this->managerContext();
    }

    public function invite(WorkspaceInvitationService $invitations): void
    {
        ['actor' => $actor, 'workspace' => $workspace] = $this->managerContext(requiresCollaboration: true);

        $this->resetErrorBag();
        $this->statusMessage = '';

        $this->validate([
            'invitationEmail' => ['required', 'string', 'email', 'max:255'],
            'invitationRole' => ['required', 'string', Rule::in([
                WorkspaceMemberRole::Admin->value,
                WorkspaceMemberRole::Editor->value,
                WorkspaceMemberRole::Viewer->value,
            ])],
        ]);

        $role = WorkspaceMemberRole::from($this->invitationRole);
        $invitations->issue($actor, $workspace, $this->invitationEmail, $role);

        $this->invitationEmail = '';
        $this->invitationRole = WorkspaceMemberRole::Viewer->value;
        $this->resetPage(pageName: 'invitations-page');
        $this->statusMessage = __('workspaces.status.invitation_sent');
    }

    public function updateMemberRole(int $memberId, WorkspaceMembershipService $memberships): void
    {
        ['actor' => $actor, 'workspace' => $workspace] = $this->managerContext(requiresCollaboration: true);

        $member = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($memberId);
        $roleKey = "memberRoles.{$member->id}";

        $this->validate([
            $roleKey => ['required', 'string', Rule::in([
                WorkspaceMemberRole::Admin->value,
                WorkspaceMemberRole::Editor->value,
                WorkspaceMemberRole::Viewer->value,
            ])],
        ]);

        $role = WorkspaceMemberRole::from($this->memberRoles[$member->id]);
        $memberships->updateRole($actor, $member, $role);

        $this->memberRoles[$member->id] = $role->value;
        $this->statusMessage = __('workspaces.status.member_role_updated');
    }

    public function removeMember(int $memberId, WorkspaceMembershipService $memberships): void
    {
        ['actor' => $actor, 'workspace' => $workspace] = $this->managerContext(requiresCollaboration: true);

        $member = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($memberId);

        $memberships->remove($actor, $member);

        unset($this->memberRoles[$member->id]);
        $this->resetPage(pageName: 'members-page');
        $this->statusMessage = __('workspaces.status.member_removed');
    }

    public function resendInvitation(int $invitationId, WorkspaceInvitationService $invitations): void
    {
        ['actor' => $actor, 'workspace' => $workspace] = $this->managerContext(requiresCollaboration: true);

        $invitation = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->findOrFail($invitationId);

        $invitations->resend($actor, $invitation);

        $this->statusMessage = __('workspaces.status.invitation_resent');
    }

    public function revokeInvitation(int $invitationId, WorkspaceInvitationService $invitations): void
    {
        ['actor' => $actor, 'workspace' => $workspace] = $this->managerContext(requiresCollaboration: true);

        $invitation = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->findOrFail($invitationId);

        $invitations->revoke($actor, $invitation);

        $this->resetPage(pageName: 'invitations-page');
        $this->statusMessage = __('workspaces.status.invitation_revoked');
    }

    public function updatedMembersPerPage(int $value): void
    {
        if (! in_array($value, [10, 25, 50, 100], true)) {
            $this->membersPerPage = 25;
        }

        $this->resetPage(pageName: 'members-page');
    }

    public function updatedInvitationsPerPage(int $value): void
    {
        if (! in_array($value, [10, 25, 50, 100], true)) {
            $this->invitationsPerPage = 25;
        }

        $this->resetPage(pageName: 'invitations-page');
    }

    public function render(WorkspaceMembershipService $memberships): View
    {
        $context = $this->managerContext();

        if (! $context['collaborationAvailable']) {
            return view('livewire.dashboard.workspace-members', [
                'actorRole' => $context['role'],
                'collaborationAvailable' => false,
                'owner' => null,
                'members' => null,
                'invitations' => null,
                'seatUsage' => null,
                'roleOptions' => $this->roleOptions($context['role']),
                'editableMemberIds' => [],
                'editableInvitationIds' => [],
                'canInvite' => false,
            ]);
        }

        $workspace = $context['workspace'];
        $actorRole = $context['role'];
        $owner = User::query()
            ->select(['id', 'name', 'email'])
            ->findOrFail($workspace->owner_user_id);

        /** @var LengthAwarePaginator<WorkspaceMember> $members */
        $members = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', '!=', $workspace->owner_user_id)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->paginate($this->membersPerPage, pageName: 'members-page');

        foreach ($members->getCollection() as $member) {
            $this->memberRoles[$member->id] ??= $member->role->value;
        }

        $invitationQuery = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at');

        if ($actorRole === WorkspaceMemberRole::Admin) {
            $invitationQuery->whereIn('role', [
                WorkspaceMemberRole::Editor->value,
                WorkspaceMemberRole::Viewer->value,
            ]);
        }

        /** @var LengthAwarePaginator<WorkspaceInvitation> $invitations */
        $invitations = $invitationQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->invitationsPerPage, pageName: 'invitations-page');

        $seatUsage = $memberships->seatUsage($workspace);
        $roleOptions = $this->roleOptions($actorRole);
        $editableMemberIds = $members->getCollection()
            ->filter(fn (WorkspaceMember $member): bool => $this->canManageMemberRole($actorRole, $member->role))
            ->pluck('id')
            ->all();
        $editableInvitationIds = $invitations->getCollection()
            ->filter(fn (WorkspaceInvitation $invitation): bool => $this->canManageInvitation($actorRole, $invitation->role))
            ->pluck('id')
            ->all();

        return view('livewire.dashboard.workspace-members', [
            'actorRole' => $actorRole,
            'collaborationAvailable' => true,
            'owner' => $owner,
            'members' => $members,
            'invitations' => $invitations,
            'seatUsage' => $seatUsage,
            'roleOptions' => $roleOptions,
            'editableMemberIds' => $editableMemberIds,
            'editableInvitationIds' => $editableInvitationIds,
            'canInvite' => $seatUsage['remaining'] === null || $seatUsage['remaining'] > 0,
        ]);
    }

    /**
     * @return array{actor: User, workspace: Workspace, role: WorkspaceMemberRole, collaborationAvailable: bool}
     */
    private function managerContext(bool $requiresCollaboration = false): array
    {
        abort_unless(config('workspaces.collaboration_enabled', false), 404);

        $authenticatedUser = auth()->user();
        abort_unless($authenticatedUser instanceof User, 403);

        $actor = $authenticatedUser->fresh();
        abort_unless($actor instanceof User, 403);

        $authorization = app(WorkspaceAuthorization::class);
        $workspace = $authorization->selectedWorkspace($actor);
        abort_unless($workspace instanceof Workspace && $workspace->id === $this->workspaceId, 403);

        $role = $authorization->role($actor, $workspace->id);
        abort_unless(in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true), 403);

        $collaborationAvailable = app(WorkspaceCapabilities::class)->allowsCollaboration($workspace);
        abort_if($requiresCollaboration && ! $collaborationAvailable, 403);

        return [
            'actor' => $actor,
            'workspace' => $workspace,
            'role' => $role,
            'collaborationAvailable' => $collaborationAvailable,
        ];
    }

    /** @return array<int, WorkspaceMemberRole> */
    private function roleOptions(WorkspaceMemberRole $actorRole): array
    {
        return $actorRole === WorkspaceMemberRole::Owner
            ? [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]
            : [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer];
    }

    private function canManageMemberRole(WorkspaceMemberRole $actorRole, WorkspaceMemberRole $memberRole): bool
    {
        if ($actorRole === WorkspaceMemberRole::Owner) {
            return in_array($memberRole, [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer], true);
        }

        return in_array($memberRole, [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer], true);
    }

    private function canManageInvitation(WorkspaceMemberRole $actorRole, WorkspaceMemberRole $invitationRole): bool
    {
        if ($actorRole === WorkspaceMemberRole::Owner) {
            return $invitationRole !== WorkspaceMemberRole::Owner;
        }

        return in_array($invitationRole, [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer], true);
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'invitationEmail' => __('workspaces.invitation.email'),
            'invitationRole' => __('workspaces.invitation.role'),
        ];
    }
}
