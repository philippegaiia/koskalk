<?php

use App\Models\Plan;
use App\Models\PlanLimit;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps an existing default plan when creating the legacy free beta plan', function (): void {
    $existingDefault = Plan::factory()->create([
        'slug' => 'existing-default',
        'is_default' => true,
    ]);

    $this->seed(PlanSeeder::class);

    $freeBeta = Plan::query()
        ->where('slug', 'free-beta')
        ->with('limits')
        ->firstOrFail();

    expect($existingDefault->fresh()->is_default)->toBeTrue()
        ->and($freeBeta->is_default)->toBeFalse()
        ->and($freeBeta->limits->pluck('value', 'key')->sortKeys()->all())->toBe([
            'formula_items_per_recipe' => 30,
            'media_assets' => 100,
            'media_labels' => 20,
            'private_ingredients' => 20,
            'production_batches' => 0,
            'saved_formula_history' => 0,
            'saved_recipes' => 15,
        ]);
});

it('leaves an existing free beta plan and its incomplete limits untouched', function (): void {
    $oldTimestamp = now()->subMonths(6)->startOfSecond();
    $freeBeta = Plan::factory()->billable('pri_existing_beta', 'pro_existing_beta')->create([
        'slug' => 'free-beta',
        'name' => 'Administrator plan name',
        'description' => 'Administrator description',
        'is_default' => false,
        'is_active' => false,
        'display_order' => 73,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);

    PlanLimit::factory()->create([
        'plan_id' => $freeBeta->id,
        'key' => 'saved_recipes',
        'value' => 0,
        'created_at' => $oldTimestamp,
        'updated_at' => $oldTimestamp,
    ]);
    PlanLimit::factory()->create([
        'plan_id' => $freeBeta->id,
        'key' => 'private_ingredients',
        'value' => null,
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
        'created_at' => $plan->created_at?->format('Y-m-d H:i:s'),
        'updated_at' => $plan->updated_at?->format('Y-m-d H:i:s'),
    ];
    $limitSnapshot = fn (): array => $freeBeta->limits()
        ->orderBy('id')
        ->get()
        ->map(fn (PlanLimit $limit): array => [
            'key' => $limit->key,
            'value' => $limit->value,
            'created_at' => $limit->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $limit->updated_at?->format('Y-m-d H:i:s'),
        ])
        ->all();

    $planBefore = $planSnapshot($freeBeta->fresh());
    $limitsBefore = $limitSnapshot();

    $this->seed(PlanSeeder::class);

    expect($planSnapshot($freeBeta->fresh()))->toBe($planBefore)
        ->and($limitSnapshot())->toBe($limitsBefore)
        ->and($freeBeta->limits()->pluck('key')->sort()->values()->all())->toBe([
            'private_ingredients',
            'saved_recipes',
        ]);
});
