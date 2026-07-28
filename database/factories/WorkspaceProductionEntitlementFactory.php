<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\ProductionBenchEntitlementStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceProductionEntitlement>
 */
class WorkspaceProductionEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'status' => ProductionBenchEntitlementStatus::Active,
            'activated_at' => now(),
            'cancelled_at' => null,
            'archive_eligible_at' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductionBenchEntitlementStatus::Cancelled,
            'cancelled_at' => now(),
            'archive_eligible_at' => now()->addMonthsNoOverflow(48),
        ]);
    }
}
