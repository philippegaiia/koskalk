<?php

namespace Database\Factories;

use App\Models\ProductionJournalEntry;
use App\Models\ProductionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionJournalEntry>
 */
class ProductionJournalEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_run_id' => ProductionRun::factory(),
            'body' => fake()->paragraph(),
            'created_by_user_id' => null,
        ];
    }
}
