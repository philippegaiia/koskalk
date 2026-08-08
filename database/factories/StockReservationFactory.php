<?php

namespace Database\Factories;

use App\Enums\StockReservationStatus;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'production_run_id' => ProductionRun::factory(),
            'production_requirement_id' => ProductionRequirement::factory(),
            'stock_lot_id' => StockLot::factory(),
            'quantity' => '1.000000000',
            'status' => StockReservationStatus::Active,
            'created_by_user_id' => User::factory(),
            'idempotency_key' => fake()->uuid(),
            'released_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => StockReservationStatus::Released,
            'released_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => StockReservationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
