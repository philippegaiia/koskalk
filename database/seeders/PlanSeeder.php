<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['slug' => 'free-beta'],
            [
                'name' => 'Free beta',
                'description' => 'Free registered launch plan. Billing is deferred, but limits remain admin-editable.',
                'is_default' => true,
                'is_active' => true,
                'display_order' => 10,
            ],
        );

        foreach ([
            'saved_recipes' => 15,
            'private_ingredients' => 20,
        ] as $key => $value) {
            $plan->limits()->firstOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }
}
