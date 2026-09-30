<?php

namespace Database\Factories;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FormulaShare> */
class FormulaShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_workspace_id' => Workspace::factory(),
            'recipient_workspace_id' => Workspace::factory(),
            'status' => FormulaShareStatus::Pending,
            'schema_version' => 1,
            'snapshot' => ['schema_version' => 1],
            'snapshot_hash' => hash('sha256', 'test snapshot'),
            'options' => [],
            'request_key' => (string) Str::uuid(),
            'sender_workspace_name' => fake()->company(),
            'sent_at' => now(),
            'expires_at' => now()->addDays(14),
        ];
    }
}
