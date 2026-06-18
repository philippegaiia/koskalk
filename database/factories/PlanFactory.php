<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'description' => null,
            'paddle_product_id' => null,
            'paddle_price_id' => null,
            'billing_interval' => null,
            'price_label' => null,
            'is_default' => false,
            'is_active' => true,
            'display_order' => 0,
        ];
    }

    public function billable(string $priceId = 'pri_test_monthly', string $productId = 'pro_test'): static
    {
        return $this->state(fn (): array => [
            'paddle_product_id' => $productId,
            'paddle_price_id' => $priceId,
            'billing_interval' => 'month',
            'price_label' => 'EUR 9 / month',
        ]);
    }

    public function hasLimit(string $key, int $value): static
    {
        return $this->afterCreating(function (Plan $plan) use ($key, $value): void {
            PlanLimit::factory()->create([
                'plan_id' => $plan->id,
                'key' => $key,
                'value' => $value,
            ]);
        });
    }
}
