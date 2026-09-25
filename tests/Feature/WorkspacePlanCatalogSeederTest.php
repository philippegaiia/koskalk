<?php

use App\Models\Plan;
use App\Models\PlanLimit;
use Database\Seeders\WorkspacePlanCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('creates the workspace plan catalogue with the reviewed limits and capabilities', function (): void {
    $expectedPlans = [
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

    $this->seed(WorkspacePlanCatalogSeeder::class);

    expect(Plan::query()->pluck('slug')->sort()->values()->all())->toBe([
        'free',
        'free-beta',
        'maker',
        'studio',
        'team',
    ]);

    foreach ($expectedPlans as $slug => $expected) {
        $plan = Plan::query()->with('limits')->where('slug', $slug)->firstOrFail();

        expect([
            'name' => $plan->name,
            'display_order' => $plan->display_order,
            'is_active' => $plan->is_active,
            'is_default' => $plan->is_default,
            'allows_collaboration' => $plan->allows_collaboration,
            'allows_production_bench' => $plan->allows_production_bench,
            'paddle_product_id' => $plan->paddle_product_id,
            'paddle_price_id' => $plan->paddle_price_id,
            'billing_interval' => $plan->billing_interval,
            'price_label' => $plan->price_label,
        ])->toBe([
            'name' => $expected['name'],
            'display_order' => $expected['display_order'],
            'is_active' => $expected['is_active'],
            'is_default' => false,
            'allows_collaboration' => $expected['allows_collaboration'],
            'allows_production_bench' => $expected['allows_production_bench'],
            'paddle_product_id' => null,
            'paddle_price_id' => null,
            'billing_interval' => null,
            'price_label' => null,
        ])
            ->and($plan->limits->pluck('value', 'key')->sortKeys()->all())->toBe(collect($expected['limits'])->sortKeys()->all());
    }

    $planSnapshots = Plan::query()->orderBy('id')->get()->map(fn (Plan $plan): array => [
        'slug' => $plan->slug,
        'name' => $plan->name,
        'is_active' => $plan->is_active,
        'is_default' => $plan->is_default,
        'allows_collaboration' => $plan->allows_collaboration,
        'allows_production_bench' => $plan->allows_production_bench,
        'updated_at' => $plan->updated_at?->format('Y-m-d H:i:s'),
    ])->all();
    $limitSnapshots = PlanLimit::query()->orderBy('id')->get()->map(fn (PlanLimit $limit): array => [
        'plan_id' => $limit->plan_id,
        'key' => $limit->key,
        'value' => $limit->value,
        'updated_at' => $limit->updated_at?->format('Y-m-d H:i:s'),
    ])->all();

    $this->seed(WorkspacePlanCatalogSeeder::class);

    expect(Plan::query()->count())->toBe(5)
        ->and(PlanLimit::query()->count())->toBe(30)
        ->and(Plan::query()->orderBy('id')->get()->map(fn (Plan $plan): array => [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'is_active' => $plan->is_active,
            'is_default' => $plan->is_default,
            'allows_collaboration' => $plan->allows_collaboration,
            'allows_production_bench' => $plan->allows_production_bench,
            'updated_at' => $plan->updated_at?->format('Y-m-d H:i:s'),
        ])->all())->toBe($planSnapshots)
        ->and(PlanLimit::query()->orderBy('id')->get()->map(fn (PlanLimit $limit): array => [
            'plan_id' => $limit->plan_id,
            'key' => $limit->key,
            'value' => $limit->value,
            'updated_at' => $limit->updated_at?->format('Y-m-d H:i:s'),
        ])->all())->toBe($limitSnapshots);
});

it('leaves an existing plan and every existing limit unchanged while adding absent plans', function (): void {
    $oldTimestamp = now()->subMonths(8)->startOfSecond();
    $existingPlan = Plan::factory()->billable('pri_existing_maker', 'pro_existing_maker')->create([
        'slug' => 'maker',
        'name' => 'Custom maker plan',
        'description' => 'An administrator-authored plan.',
        'is_default' => true,
        'is_active' => false,
        'display_order' => 83,
        'allows_collaboration' => true,
        'allows_production_bench' => null,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);

    PlanLimit::factory()->create([
        'plan_id' => $existingPlan->id,
        'key' => 'workspace_members',
        'value' => null,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);
    PlanLimit::factory()->create([
        'plan_id' => $existingPlan->id,
        'key' => 'saved_recipes',
        'value' => 0,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);
    PlanLimit::factory()->create([
        'plan_id' => $existingPlan->id,
        'key' => 'production_batches',
        'value' => 12,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);

    $planSnapshot = fn (Plan $plan): array => [
        'name' => $plan->name,
        'description' => $plan->description,
        'paddle_product_id' => $plan->paddle_product_id,
        'paddle_price_id' => $plan->paddle_price_id,
        'billing_interval' => $plan->billing_interval,
        'price_label' => $plan->price_label,
        'is_default' => $plan->is_default,
        'is_active' => $plan->is_active,
        'display_order' => $plan->display_order,
        'allows_collaboration' => $plan->allows_collaboration,
        'allows_production_bench' => $plan->allows_production_bench,
        'created_at' => $plan->created_at?->format('Y-m-d H:i:s'),
        'updated_at' => $plan->updated_at?->format('Y-m-d H:i:s'),
    ];
    $limitSnapshot = fn (): array => $existingPlan->limits()
        ->orderBy('id')
        ->get()
        ->map(fn (PlanLimit $limit): array => [
            'key' => $limit->key,
            'value' => $limit->value,
            'created_at' => $limit->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $limit->updated_at?->format('Y-m-d H:i:s'),
        ])
        ->all();

    $planBefore = $planSnapshot($existingPlan->fresh());
    $limitsBefore = $limitSnapshot();

    $this->seed(WorkspacePlanCatalogSeeder::class);

    expect(Plan::query()->count())->toBe(5)
        ->and($planSnapshot($existingPlan->fresh()))->toBe($planBefore)
        ->and($limitSnapshot())->toBe($limitsBefore)
        ->and($existingPlan->limits()->pluck('key')->sort()->values()->all())->toBe([
            'production_batches',
            'saved_recipes',
            'workspace_members',
        ])
        ->and($existingPlan->fresh()->allows_production_bench)->toBeNull();
});

it('rolls back the full catalogue when creating a later plan fails', function (): void {
    $eventName = 'eloquent.creating: '.Plan::class;
    Event::listen($eventName, function (Plan $plan): void {
        if ($plan->slug === 'studio') {
            throw new LogicException('Simulated plan creation failure.');
        }
    });

    try {
        expect(fn () => app(WorkspacePlanCatalogSeeder::class)->run())
            ->toThrow(LogicException::class, 'Simulated plan creation failure.');
    } finally {
        Event::forget($eventName);
    }

    expect(Plan::query()->count())->toBe(0)
        ->and(PlanLimit::query()->count())->toBe(0);
});
