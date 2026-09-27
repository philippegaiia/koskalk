<?php

namespace Database\Factories;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceInvitation> */
class WorkspaceInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceMemberRole::Editor,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addDays(7),
            'last_sent_at' => now(),
        ];
    }
}
