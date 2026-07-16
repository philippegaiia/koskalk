<?php

namespace App\Services;

use App\Models\BetaInvite;
use App\Models\User;
use App\Notifications\BetaWorkspaceInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BetaInviteService
{
    private const int EXPIRY_DAYS = 7;

    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly WorkspaceProvisioner $workspaceProvisioner,
    ) {}

    public function issue(User $administrator, string $email, string $workspaceName): string
    {
        if (! $administrator->is_admin) {
            throw ValidationException::withMessages([
                'invitation' => 'Only a platform administrator can issue beta invitations.',
            ]);
        }

        $email = Str::lower(trim($email));
        $workspaceName = trim($workspaceName);

        Validator::validate([
            'email' => $email,
            'workspace_name' => $workspaceName,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'workspace_name' => ['required', 'string', 'max:255'],
        ]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays(self::EXPIRY_DAYS);

        $invite = DB::transaction(function () use ($administrator, $email, $expiresAt, $token, $workspaceName): BetaInvite {
            $invite = BetaInvite::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists() || $invite?->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'email' => 'This email address already has an account.',
                ]);
            }

            $invite ??= new BetaInvite(['email' => $email]);

            $invite->fill([
                'workspace_name' => $workspaceName,
                'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $administrator->id,
                'expires_at' => $expiresAt,
                'accepted_at' => null,
                'revoked_at' => null,
            ]);
            $invite->save();

            return $invite;
        });

        Notification::route('mail', $invite->email)->notify(
            new BetaWorkspaceInvitation($token, $invite->workspace_name, Carbon::instance($invite->expires_at)),
        );

        return $token;
    }

    public function findPending(string $token): ?BetaInvite
    {
        if (! ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }

        $invite = BetaInvite::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invite?->isPending() ? $invite : null;
    }

    /**
     * @param  array{name: string, password: string}  $attributes
     */
    public function accept(string $token, array $attributes): ?User
    {
        if (! ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }

        return DB::transaction(function () use ($attributes, $token): ?User {
            $invite = BetaInvite::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invite instanceof BetaInvite || ! $invite->isPending()) {
                return null;
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$invite->email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email address already has an account.',
                ]);
            }

            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $invite->email,
                'password' => $attributes['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->workspaceProvisioner->ensureOwnerWorkspace($user, $invite->workspace_name);
            $this->entitlementService->assignDefaultPlan($user);

            if (! $user->entitlements()->exists()) {
                throw new RuntimeException('No active default plan is configured.');
            }

            $invite->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }
}
