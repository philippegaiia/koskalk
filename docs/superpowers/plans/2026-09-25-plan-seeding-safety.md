# Plan seeding safety implementation plan

> **For agentic workers:** Use superpowers:subagent-driven-development or superpowers:executing-plans task by task. Luna max implements this bounded change; Astra medium reviews preservation and transaction behavior.

**Goal:** Make the existing PlanSeeder skip an existing Free beta plan completely, without changing its current fresh-install defaults.

**Architecture:** Wrap creation and initial limits in a transaction. Return before saving an existing plan or inserting any limits. Preserve the current default when creating a missing beta plan in a populated catalogue. The new plan catalogue and capability rollout are separate tasks in `2026-09-25-multi-user-workspace-rollout.md`.

**Tech stack:** Existing Laravel Eloquent and Pest. Confirm installed versions and search official package docs before coding. No schema or dependency changes.

## Task 1 — characterize preservation

Files: create `tests/Feature/PlanSeederSafetyTest.php`; existing regression `tests/Feature/EntitlementLimitsTest.php`.

- [ ] Read testing-best-practices and applicable `.ai/rules` before editing. Create with `php artisan make:test --pest PlanSeederSafetyTest --no-interaction`.
- [ ] Replace the generated test with:

```php
<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips an existing beta plan including absent limit keys', function (): void {
    $plan = Plan::factory()
        ->billable()
        ->hasLimit('saved_recipes', 0)
        ->hasLimit('media_assets', null)
        ->create([
            'slug' => 'free-beta',
            'name' => 'Custom tester offer',
            'is_active' => false,
            'is_default' => false,
        ]);
    $default = Plan::factory()->create(['is_default' => true]);
    $before = $plan->getRawOriginal();
    $limitsBefore = $plan->limits()->orderBy('id')->get()->toArray();

    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    expect($plan->fresh()->getRawOriginal())->toBe($before)
        ->and($plan->limits()->orderBy('id')->get()->toArray())->toBe($limitsBefore)
        ->and($default->fresh()->is_default)->toBeTrue();
});

it('does not replace the default when creating a missing beta plan', function (): void {
    $default = Plan::factory()->create(['is_default' => true]);

    $this->seed(PlanSeeder::class);

    expect($default->fresh()->is_default)->toBeTrue()
        ->and(Plan::query()->where('slug', 'free-beta')->firstOrFail()->is_default)
        ->toBeFalse();
});
```

- [ ] Run `php artisan test --compact tests/Feature/PlanSeederSafetyTest.php`. Expected before implementation: both fail, due to filled missing limits and default replacement respectively. Investigate any unrelated failure first.

## Task 2 — transaction and whole-plan skip

File: replace `database/seeders/PlanSeeder.php` with:

```php
<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
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
                $plan->limits()->create([
                    'key' => $key,
                    'value' => $value,
                ]);
            }
        });
    }
}
```

- [ ] Run `php artisan test --compact tests/Feature/PlanSeederSafetyTest.php tests/Feature/EntitlementLimitsTest.php`. Expected: PASS, including the unchanged fresh-install limit test. This tranche deliberately preserves those legacy defaults; future catalogue defaults must not be confused with retroactive updates.
- [ ] Review transaction behavior on disposable PostgreSQL. Deployment commands run serially; this code does not claim to arbitrate concurrent administrator default changes. The future dedicated catalogue creates only non-default rows.
- [ ] Run `vendor/bin/pint --dirty --format agent`, then rerun affected tests if formatting changes behavior. No Filament files change in this tranche.
- [ ] Run `graphify update .` after application edits. Inspect generated changes and avoid bundling unrelated output.
- [ ] Review `git diff --check` and the staged diff, then commit only the seeder and its test as `fix: preserve existing plans completely during seeding`.

## Completion boundary

This tranche adds no plans, grants, memberships, prices or production changes. It establishes the preservation rule needed by the catalogue rollout. Run the dedicated catalogue in production only after that separate implementation and deployment preview have been reviewed. Never use the general DatabaseSeeder for this rollout.
