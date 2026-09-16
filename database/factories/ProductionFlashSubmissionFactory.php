<?php

namespace Database\Factories;

use App\Models\ProductionFlashSubmission;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionFlashSubmission> */
class ProductionFlashSubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'idempotency_hash' => hash('sha256', fake()->uuid()),
            'request_hash' => hash('sha256', fake()->uuid()),
            'uses_production_locations' => false,
            'production_ids' => [],
        ];
    }
}
