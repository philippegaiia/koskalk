<?php

use App\Livewire\ProductionBench\Production\ProductionCalendar;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\ProductionRunStatus;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('returns workspace-scoped production and task events for a date range', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    $task = ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Cut and cure',
        'colour_snapshot' => '#8f5c38',
        'scheduled_for' => '2026-08-21',
    ]);
    $other = productionCalendarFixture();
    productionCalendarProduction($other, '2026-08-20', ProductionRunStatus::Scheduled, [
        'planning_batch_number' => 'T00002',
    ]);

    $component = Livewire::actingAs($fixture['owner'])->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01');
    $events = $component->instance()->events();

    expect($events)->toHaveCount(2)
        ->and(collect($events)->pluck('extendedProps.eventType')->sort()->values()->all())->toBe(['production', 'task'])
        ->and(collect($events)->pluck('title')->all())->toContain('T00001 · Olive soap', 'Cut and cure')
        ->and(collect($events)->firstWhere('extendedProps.eventType', 'task')['backgroundColor'])->toBe('#8F5C38')
        ->and(collect($events)->firstWhere('extendedProps.eventType', 'task')['extendedProps']['colour'])->toBe('#8F5C38')
        ->and(collect($events)->pluck('extendedProps.url')->join('|'))->toContain($production->public_id)
        ->and(collect($events)->pluck('extendedProps.url')->join('|'))->not->toContain($other['production']->public_id)
        ->and(collect($events)->firstWhere('extendedProps.eventType', 'production')['end'])->toBe('2026-08-21')
        ->and(collect($events)->firstWhere('extendedProps.eventType', 'task')['end'])->toBe('2026-08-22')
        ->and($task->productionRun->is($production))->toBeTrue();
});

it('uses permanent batch numbers before planning references in production event titles', function (): void {
    $fixture = productionCalendarFixture();
    $permanent = productionCalendarProduction($fixture, '2026-08-22', ProductionRunStatus::Scheduled, [
        'planning_batch_number' => 'T00002',
        'batch_number' => 'B-00001-FR',
        'batch_number_serial' => 1,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $fixture['owner']->id,
    ]);
    $task = ProductionTask::factory()->for($fixture['workspace'])->for($permanent, 'productionRun')->create([
        'name_snapshot' => 'Cure soap',
        'scheduled_for' => '2026-08-23',
    ]);

    $events = Livewire::actingAs($fixture['owner'])->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->instance()
        ->events();

    expect(collect($events)->firstWhere('id', 'production-'.$fixture['production']->id)['title'])
        ->toBe('T00001 · Olive soap')
        ->and(collect($events)->firstWhere('id', 'production-'.$permanent->id)['title'])
        ->toBe('B-00001-FR · Olive soap')
        ->and(collect($events)->firstWhere('id', 'task-'.$task->id)['title'])
        ->toBe('Cure soap')
        ->and(collect($events)->firstWhere('id', 'task-'.$task->id)['extendedProps']['url'])
        ->toContain($permanent->public_id);
});

it('filters tasks and completed events without exposing mutation controls', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    $production->update(['status' => ProductionRunStatus::Completed]);
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Completed task',
        'scheduled_for' => '2026-08-21',
        'completed_at' => now(),
    ]);

    $component = Livewire::actingAs($fixture['owner'])->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01');

    expect($component->instance()->events())->toBe([]);

    $component->set('showCompleted', true);
    expect($component->instance()->events())->toHaveCount(2);

    $component->set('showTasks', false);
    expect($component->instance()->events())->toHaveCount(1)
        ->and($component->instance()->events()[0]['extendedProps']['eventType'])->toBe('production');
});

it('renders the calendar page with month, week, and agenda controls', function (): void {
    $fixture = productionCalendarFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.calendar'))
        ->assertOk()
        ->assertSee('Production calendar')
        ->assertSee('Day')
        ->assertSee('Month')
        ->assertSee('Week')
        ->assertSee('Agenda')
        ->assertSee('productionCalendar')
        ->assertSee('data-calendar-options')
        ->assertSee('productionCalendarComponent');
});

it('dispatches fresh events when a cached calendar page is refreshed', function (): void {
    $fixture = productionCalendarFixture();
    $task = ProductionTask::factory()->for($fixture['workspace'])->for($fixture['production'], 'productionRun')->create([
        'name_snapshot' => 'Cut and cure',
        'scheduled_for' => '2026-08-21',
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('refreshEvents')
        ->assertDispatched('production-calendar-updated', function (string $event, array $payload) use ($fixture, $task): bool {
            $events = collect($payload['events'] ?? []);

            return $event === 'production-calendar-updated'
                && $payload['showProductions'] === true
                && $payload['showTasks'] === true
                && $payload['showCompleted'] === false
                && $events->contains('id', 'production-'.$fixture['production']->id)
                && $events->contains('id', 'task-'.$task->id);
        });
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, production: ProductionRun}
 */
it('shows snapshot product names in event titles after the live product is renamed', function (): void {
    $fixture = productionCalendarFixture();
    $fixture['recipe']->update(['name' => 'Renamed live product']);
    RecipeVersion::withoutGlobalScopes()->whereKey($fixture['version']->id)->delete();

    $events = Livewire::actingAs($fixture['owner'])->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->instance()
        ->events();

    expect(collect($events)->firstWhere('id', 'production-'.$fixture['production']->id)['title'])
        ->toBe('T00001 · Olive soap')
        ->and(collect($events)->pluck('title'))->not->toContain('Renamed live product');
});

function productionCalendarFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->productionEntitlement()->create([
        'status' => 'active',
        'activated_at' => now(),
    ]);
    Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $family = ProductFamily::factory()->create([
        'slug' => 'calendar-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => true,
    ]);
    $production = productionCalendarProduction([
        'owner' => $owner,
        'workspace' => $workspace,
        'recipe' => $recipe,
        'version' => $version,
    ], '2026-08-20', ProductionRunStatus::Scheduled);

    return compact('owner', 'workspace', 'recipe', 'version', 'production');
}

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function productionCalendarProduction(array $fixture, string $plannedFor, ProductionRunStatus $status, array $identity = []): ProductionRun
{
    return ProductionRun::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'recipe_id' => $fixture['recipe']->id,
        'recipe_version_id' => $fixture['version']->id,
        'recipe_name_snapshot' => $fixture['recipe']->name,
        'status' => $status,
        'source' => 'direct',
        'planned_for' => $plannedFor,
        'basis_kind' => 'oil_mass',
        'basis_quantity_grams' => '10000.000000000',
        'basis_input_value' => '10.000000000',
        'basis_input_unit' => 'kg',
        'expected_units' => 100,
        'idempotency_key' => fake()->uuid(),
        'created_by_user_id' => $fixture['owner']->id,
        'planning_batch_number' => $identity['planning_batch_number'] ?? 'T00001',
        'batch_number' => $identity['batch_number'] ?? null,
        'batch_number_serial' => $identity['batch_number_serial'] ?? null,
        'batch_number_assigned_at' => $identity['batch_number_assigned_at'] ?? null,
        'batch_number_assigned_by_user_id' => $identity['batch_number_assigned_by_user_id'] ?? null,
    ]);
}
