<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('backfills formula line limits for free and billable plans without overwriting existing values', function (): void {
    $freePlan = Plan::factory()->create(['slug' => 'free-beta']);
    $paidPlan = Plan::factory()->billable('pri_pro')->create(['slug' => 'pro']);
    $editedPlan = Plan::factory()->billable('pri_edited')->create(['slug' => 'edited']);
    $internalPlan = Plan::factory()->create(['slug' => 'internal']);
    $editedPlan->limits()->create(['key' => 'formula_items_per_recipe', 'value' => 37]);

    $migration = require database_path('migrations/2026_08_12_130000_backfill_formula_item_plan_limits.php');
    $migration->up();

    expect(DB::table('plan_limits')->where('plan_id', $freePlan->id)->where('key', 'formula_items_per_recipe')->value('value'))->toBe(30)
        ->and(DB::table('plan_limits')->where('plan_id', $paidPlan->id)->where('key', 'formula_items_per_recipe')->value('value'))->toBe(50)
        ->and(DB::table('plan_limits')->where('plan_id', $editedPlan->id)->where('key', 'formula_items_per_recipe')->value('value'))->toBe(37)
        ->and(DB::table('plan_limits')->where('plan_id', $internalPlan->id)->where('key', 'formula_items_per_recipe')->exists())->toBeFalse();

    $migration->down();

    expect(DB::table('plan_limits')->where('plan_id', $freePlan->id)->where('key', 'formula_items_per_recipe')->exists())->toBeFalse()
        ->and(DB::table('plan_limits')->where('plan_id', $paidPlan->id)->where('key', 'formula_items_per_recipe')->exists())->toBeFalse()
        ->and(DB::table('plan_limits')->where('plan_id', $editedPlan->id)->where('key', 'formula_items_per_recipe')->value('value'))->toBe(37);
});
