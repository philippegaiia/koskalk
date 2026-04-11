<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class SettingsIndex extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $userId = null;

    public string $activeTab = 'profile';

    // Profile fields
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public $avatar;

    // Company fields
    #[Locked]
    public ?int $companyId = null;

    public string $companyName = '';

    public string $companyCurrency = 'EUR';

    // Member invitation fields
    public string $inviteEmail = '';

    public string $inviteRole = 'editor';

    public string $profileStatus = '';

    public string $profileMessage = '';

    public string $companyStatus = '';

    public string $companyMessage = '';

    public string $memberStatus = '';

    public string $memberMessage = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->userId = $user->id;
        $this->name = $user->name ?? '';
        $this->email = $user->email ?? '';

        $company = $user->company();

        if ($company instanceof Workspace) {
            $this->companyId = $company->id;
            $this->companyName = $company->name;
            $this->companyCurrency = $company->default_currency ?? 'EUR';
        }
    }

    public function saveProfile(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        if ($this->avatar) {
            $this->validate(['avatar' => ['image', 'max:2048']]);
            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        $this->profileStatus = 'success';
        $this->profileMessage = 'Profile updated.';
        $this->avatar = null;
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed:newPasswordConfirmation'],
        ]);

        /** @var User $user */
        $user = auth()->user();

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'The current password is incorrect.',
            ]);
        }

        $user->update(['password' => Hash::make($this->newPassword)]);

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';

        $this->profileStatus = 'success';
        $this->profileMessage = 'Password updated.';
    }

    public function saveCompany(): void
    {
        $this->validate([
            'companyName' => ['required', 'string', 'max:255'],
            'companyCurrency' => ['required', 'string', 'size:3', Rule::in(array_keys(config('currencies', [])))],
        ]);

        /** @var User $user */
        $user = auth()->user();

        if ($this->companyId) {
            $company = Workspace::withoutGlobalScopes()->find($this->companyId);

            if (! $company instanceof Workspace || $company->owner_user_id !== $user->id) {
                abort(403);
            }

            $company->fill([
                'name' => $this->companyName,
                'default_currency' => $this->companyCurrency,
            ]);
            $company->save();
        } else {
            $company = Workspace::withoutGlobalScopes()->create([
                'owner_user_id' => $user->id,
                'name' => $this->companyName,
                'slug' => Str::slug($this->companyName.'-'.Str::random(6)),
                'default_currency' => $this->companyCurrency,
            ]);

            $company->members()->create([
                'user_id' => $user->id,
                'role' => WorkspaceMemberRole::Owner->value,
            ]);

            $this->companyId = $company->id;
        }

        $this->companyStatus = 'success';
        $this->companyMessage = 'Company settings saved.';
    }

    public function inviteMember(): void
    {
        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $company = $this->resolveCompanyForManagement($user);

        $existingMember = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('email', $this->inviteEmail))
            ->exists();

        if ($existingMember) {
            throw ValidationException::withMessages([
                'inviteEmail' => 'This person is already a member.',
            ]);
        }

        $existingInvitation = WorkspaceInvitation::query()
            ->where('workspace_id', $company->id)
            ->where('email', $this->inviteEmail)
            ->whereNull('accepted_at')
            ->exists();

        if ($existingInvitation) {
            throw ValidationException::withMessages([
                'inviteEmail' => 'An invitation has already been sent to this email.',
            ]);
        }

        WorkspaceInvitation::query()->create([
            'workspace_id' => $company->id,
            'invited_by' => $user->id,
            'email' => $this->inviteEmail,
            'role' => $this->inviteRole,
        ]);

        $this->inviteEmail = '';
        $this->inviteRole = 'editor';

        $this->memberStatus = 'success';
        $this->memberMessage = 'Invitation sent.';
    }

    public function removeMember(int $memberId): void
    {
        /** @var User $user */
        $user = auth()->user();
        $company = $this->resolveCompanyForManagement($user);

        $member = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $company->id)
            ->whereKey($memberId)
            ->first();

        if (! $member instanceof WorkspaceMember) {
            return;
        }

        if ($member->user_id === $user->id) {
            return;
        }

        $member->delete();

        $this->memberStatus = 'success';
        $this->memberMessage = 'Member removed.';
    }

    public function getMembersProperty()
    {
        if (! $this->companyId) {
            return collect();
        }

        return WorkspaceMember::withoutGlobalScopes()
            ->with('user')
            ->where('workspace_id', $this->companyId)
            ->get();
    }

    public function getPendingInvitationsProperty()
    {
        if (! $this->companyId) {
            return collect();
        }

        return WorkspaceInvitation::query()
            ->where('workspace_id', $this->companyId)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    private function resolveCompanyForManagement(User $user): Workspace
    {
        $company = Workspace::withoutGlobalScopes()->find($this->companyId);

        if (! $company instanceof Workspace || $company->owner_user_id !== $user->id) {
            abort(403, 'Only the company owner can manage members.');
        }

        return $company;
    }

    public function render()
    {
        return view('livewire.dashboard.settings-index');
    }
}
