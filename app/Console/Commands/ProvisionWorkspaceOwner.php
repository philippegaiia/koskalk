<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\WorkspaceMemberRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

#[Signature('app:provision-workspace-owner
            {email : The owner email address}
            {--name= : The owner name}
            {--workspace= : The workspace name}')]
#[Description('Provision the verified owner of an invite-only workspace')]
class ProvisionWorkspaceOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EntitlementService $entitlementService): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('A user with this email address already exists.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Owner name')));
        $workspaceName = trim((string) ($this->option('workspace') ?: $this->ask('Workspace name')));
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'workspace' => $workspaceName,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'workspace' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($email, $entitlementService, $name, $password, $workspaceName): void {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $workspace = Workspace::withoutGlobalScopes()->create([
                    'owner_user_id' => $user->id,
                    'name' => $workspaceName,
                    'slug' => Str::slug($workspaceName).'-'.Str::lower(Str::random(8)),
                    'default_currency' => 'EUR',
                ]);

                $workspace->members()->create([
                    'user_id' => $user->id,
                    'role' => WorkspaceMemberRole::Owner->value,
                ]);

                $entitlementService->assignDefaultPlan($user);

                if (! $user->entitlements()->exists()) {
                    throw new RuntimeException('No active default plan is configured.');
                }
            });
        } catch (Throwable) {
            $this->error('Provisioning failed. Confirm that an active default plan exists and try again.');

            return self::FAILURE;
        }

        $this->info("Provisioned {$email} as the verified workspace owner.");

        return self::SUCCESS;
    }
}
