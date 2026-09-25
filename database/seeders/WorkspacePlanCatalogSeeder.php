<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkspacePlanCatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{
     *     name: string,
     *     display_order: int,
     *     is_active: bool,
     *     allows_collaboration: bool,
     *     allows_production_bench: bool,
     *     limits: array<string, int>
     * }>
     */
    private const array PLANS = [
        'free' => [
            'name' => 'Free',
            'display_order' => 10,
            'is_active' => false,
            'allows_collaboration' => false,
            'allows_production_bench' => false,
            'limits' => [
                'workspace_members' => 1,
                'saved_recipes' => 15,
                'private_ingredients' => 20,
                'formula_items_per_recipe' => 30,
                'media_assets' => 100,
                'media_labels' => 20,
            ],
        ],
        'maker' => [
            'name' => 'Maker',
            'display_order' => 20,
            'is_active' => false,
            'allows_collaboration' => false,
            'allows_production_bench' => false,
            'limits' => [
                'workspace_members' => 1,
                'saved_recipes' => 100,
                'private_ingredients' => 100,
                'formula_items_per_recipe' => 100,
                'media_assets' => 500,
                'media_labels' => 50,
            ],
        ],
        'studio' => [
            'name' => 'Studio',
            'display_order' => 30,
            'is_active' => false,
            'allows_collaboration' => false,
            'allows_production_bench' => true,
            'limits' => [
                'workspace_members' => 1,
                'saved_recipes' => 500,
                'private_ingredients' => 500,
                'formula_items_per_recipe' => 200,
                'media_assets' => 2000,
                'media_labels' => 100,
            ],
        ],
        'team' => [
            'name' => 'Team',
            'display_order' => 40,
            'is_active' => false,
            'allows_collaboration' => true,
            'allows_production_bench' => true,
            'limits' => [
                'workspace_members' => 5,
                'saved_recipes' => 1000,
                'private_ingredients' => 1000,
                'formula_items_per_recipe' => 200,
                'media_assets' => 4000,
                'media_labels' => 200,
            ],
        ],
        'free-beta' => [
            'name' => 'Free beta',
            'display_order' => 50,
            'is_active' => true,
            'allows_collaboration' => true,
            'allows_production_bench' => true,
            'limits' => [
                'workspace_members' => 5,
                'saved_recipes' => 1000,
                'private_ingredients' => 1000,
                'formula_items_per_recipe' => 200,
                'media_assets' => 4000,
                'media_labels' => 200,
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::PLANS as $slug => $attributes) {
                $plan = Plan::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $attributes['name'],
                        'description' => null,
                        'is_default' => false,
                        'is_active' => $attributes['is_active'],
                        'display_order' => $attributes['display_order'],
                        'allows_collaboration' => $attributes['allows_collaboration'],
                        'allows_production_bench' => $attributes['allows_production_bench'],
                    ],
                );

                if (! $plan->wasRecentlyCreated) {
                    continue;
                }

                foreach ($attributes['limits'] as $key => $value) {
                    $plan->limits()->firstOrCreate(
                        ['key' => $key],
                        ['value' => $value],
                    );
                }
            }
        });
    }
}
