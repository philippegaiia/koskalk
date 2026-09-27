<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceMemberInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitationService
{
    public function __construct(private readonly WorkspaceMembershipService $memberships, private readonly WorkspaceAuthorization $authorization) {}

    public function issue(User $actor, Workspace $workspace, string $email, WorkspaceMemberRole $role): WorkspaceInvitation
    {
        $email = mb_strtolower(trim($email));
        Validator::validate(['email' => $email], ['email' => ['required', 'email', 'max:255']]);

        return DB::transaction(function () use ($actor, $workspace, $email, $role): WorkspaceInvitation {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->authorize(User::query()->findOrFail($actor->id), $workspace, $role);
            $this->throttle($actor, $workspace, $email);
            $this->assertNotMember($workspace, $email);
            if (WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $email)->pending()->exists()) {
                $this->fail('duplicate_invitation');
            }
            $this->assertCapacity($workspace, 1);
            $this->assertPendingBound($workspace);
            $token = bin2hex(random_bytes(32));
            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->id, 'email' => $email, 'role' => $role,
                'invited_by_user_id' => $actor->id, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7), 'last_sent_at' => now(),
            ]);
            $this->notify($invitation, $workspace, $token);

            return $invitation;
        }, attempts: 5);
    }

    public function resend(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->workspace_id);
            $invitation = WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->findOrFail($invitation->id);
            $this->authorize(User::query()->findOrFail($actor->id), $workspace, $invitation->role);
            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                $this->fail('unavailable');
            }
            $this->throttle($actor, $workspace, $invitation->email);
            $this->assertNotMember($workspace, $invitation->email);
            if (! $invitation->isPending()) {
                if (WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $invitation->email)->pending()->exists()) {
                    $this->fail('duplicate_invitation');
                }
                $this->assertPendingBound($workspace);
            }
            $this->assertCapacity($workspace, $invitation->isPending() ? 0 : 1);
            $token = bin2hex(random_bytes(32));
            $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'last_sent_at' => now(), 'invited_by_user_id' => $actor->id]);
            $this->notify($invitation, $workspace, $token);
        }, attempts: 5);
    }

    public function revoke(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->workspace_id);
            $invitation = WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->findOrFail($invitation->id);
            $this->authorize(User::query()->findOrFail($actor->id), $workspace, $invitation->role);
            if ($invitation->accepted_at !== null) {
                $this->fail('unavailable');
            }
            $invitation->update(['revoked_at' => now()]);
        }, attempts: 5);
    }

    public function findPending(string $token): ?WorkspaceInvitation
    {
        if (! config('workspaces.collaboration_enabled') || ! ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }

        return WorkspaceInvitation::query()->with('workspace')->where('token_hash', hash('sha256', $token))->pending()->first();
    }

    /** @param array{name?: string, password?: string, password_confirmation?: string} $attributes */
    public function accept(string $token, ?User $user, array $attributes = []): User
    {
        abort_unless(config('workspaces.collaboration_enabled') && ctype_xdigit($token) && strlen($token) === 64, 404);
        $candidate = WorkspaceInvitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        try {
            return DB::transaction(function () use ($candidate, $token, $user, $attributes): User {
                $user = $user === null ? null : User::query()->lockForUpdate()->findOrFail($user->id);
                $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($candidate->workspace_id);
                $invitation = WorkspaceInvitation::query()->where('token_hash', hash('sha256', $token))->where('workspace_id', $workspace->id)->firstOrFail();
                $this->memberships->assertEnabled($workspace);
                if ($user !== null) {
                    abort_unless($user->hasVerifiedEmail() && mb_strtolower(trim($user->email)) === $invitation->email, 403);
                }
                if ($invitation->accepted_at !== null) {
                    abort_unless($user !== null && $invitation->accepted_by_user_id === $user->id && WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists(), 404);
                    $user->forceFill(['active_workspace_id' => $workspace->id])->save();

                    return $user;
                }
                abort_unless($invitation->isPending(), 404);
                $inviter = $invitation->inviter;
                abort_unless($inviter !== null, 403);
                Gate::forUser($inviter)->authorize('acceptInvitation', [WorkspaceMember::class, $workspace, $invitation->role]);
                $this->assertCapacity($workspace, 0);
                if ($user === null) {
                    if (User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->exists()) {
                        $this->fail('sign_in');
                    }
                    $validated = Validator::validate($attributes, ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', Password::defaults()]]);
                    $user = User::query()->create(['name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password']]);
                    $user->forceFill(['email_verified_at' => now()]);
                }
                if ($user->id !== $workspace->owner_user_id) {
                    WorkspaceMember::withoutGlobalScopes()->firstOrCreate(['workspace_id' => $workspace->id, 'user_id' => $user->id], ['role' => $invitation->role]);
                }
                $user->forceFill(['active_workspace_id' => $workspace->id])->save();
                $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->id]);
                $user->forgetAccessibleWorkspaceIds();

                return $user;
            }, attempts: 5);
        } catch (UniqueConstraintViolationException $exception) {
            if ($user === null && User::query()->whereRaw('LOWER(email) = ?', [$candidate->email])->exists()) {
                $this->fail('sign_in');
            }
            throw $exception;
        }
    }

    private function authorize(User $actor, Workspace $workspace, WorkspaceMemberRole $role): void
    {
        $this->memberships->assertEnabled($workspace);
        abort_unless($this->authorization->selectedWorkspace($actor)?->id === $workspace->id, 403);
        $actor->forgetAccessibleWorkspaceIds();
        Gate::forUser($actor)->authorize('create', [WorkspaceMember::class, $role]);
    }

    private function assertNotMember(Workspace $workspace, string $email): void
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->where(function (Builder $query) use ($workspace): void {
            $query->whereKey($workspace->owner_user_id)->orWhereIn('id', WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->select('user_id'));
        })->exists()) {
            $this->fail('already_member');
        }
    }

    private function assertCapacity(Workspace $workspace, int $additional): void
    {
        $usage = $this->memberships->seatUsage($workspace);
        if ($usage['limit'] !== null && $usage['limit'] < $usage['used'] + $additional) {
            $this->fail('seats_full');
        }
    }

    private function assertPendingBound(Workspace $workspace): void
    {
        if (WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->count() >= config('workspaces.maximum_pending_invitations', 100)) {
            $this->fail('invitation_limit');
        }
    }

    private function throttle(User $actor, Workspace $workspace, string $email): void
    {
        $key = 'workspace-invitations:'.$workspace->id.':'.$actor->id;
        $workspaceKey = 'workspace-invitations:workspace:'.$workspace->id;
        if (RateLimiter::tooManyAttempts($key, 20) || RateLimiter::tooManyAttempts($workspaceKey, 60)) {
            $this->fail('rate_limited');
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($workspaceKey, 60);
        if (WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $email)->where('last_sent_at', '>', now()->subSeconds(config('workspaces.invitation_recipient_cooldown_seconds', 60)))->exists()) {
            $this->fail('cooldown');
        }
    }

    private function notify(WorkspaceInvitation $invitation, Workspace $workspace, string $token): void
    {
        DB::afterCommit(fn () => Notification::route('mail', $invitation->email)->notify(new WorkspaceMemberInvitation($token, $workspace->name)));
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['invitation' => __('workspaces.validation.'.$key)]);
    }
}
