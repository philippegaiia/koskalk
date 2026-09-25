<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $plan = Plan::query()->firstOrCreate(
                ['slug' => 'free-beta'],
                [
                    'name' => 'Free beta',
                    'description' => 'Free registered launch plan. Limits remain admin-editable.',
                    'is_default' => ! Plan::query()->where('is_default', true)->exists(),
                    'is_active' => true,
                    'display_order' => 10,
                ],
            );

            if (! $plan->wasRecentlyCreated) {
                return;
            }

            foreach ([
                'saved_recipes' => 15,
                'private_ingredients' => 20,
                'formula_items_per_recipe' => 30,
                'production_batches' => 0,
                'saved_formula_history' => 0,
                'media_assets' => 100,
                'media_labels' => 20,
            ] as $key => $value) {
                $plan->limits()->firstOrCreate(
                    ['key' => $key],
                    ['value' => $value],
                );
            }
        });
    }
}
