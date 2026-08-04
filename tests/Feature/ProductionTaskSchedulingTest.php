<?php

use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\GenerateProductionTasks;
use App\Actions\Production\PlanProduction;
use App\Actions\Production\ReopenProductionTask;
use App\Actions\Production\RescheduleProduction;
use App\Actions\Production\RescheduleProductionTask;
use App\Actions\Production\ResetProductionTaskDate;
use App\Actions\Production\SaveEmployee;
use App\Actions\Production\SaveProductionHoliday;
use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionRunStatus;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('generates an anchored task sequence and skips non-working days after production', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $cure = productionTaskSchedulingType($fixture, 'Cure', 1440);
    $set = productionTaskSchedulingSet($fixture, $pour, $cure, [0, 2]);

    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    app(SaveProductionHoliday::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Closure',
        date: '2026-08-17',
    );

    $production = productionTaskSchedulingPlan($fixture, '2026-08-15');
    $tasks = $production->tasks()->orderBy('id')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->days_after_production)->toBe(0)
        ->and($tasks[0]->scheduled_for->toDateString())->toBe('2026-08-15')
        ->and($tasks[1]->days_after_production)->toBe(2)
        ->and($tasks[1]->scheduled_for->toDateString())->toBe('2026-08-18')
        ->and($tasks[0]->scheduling_mode)->toBe('automatic')
        ->and($tasks[1]->duration_minutes)->toBe(1440);
});

it('matches recurring holidays in later years without shifting the explicit production date', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $cure = productionTaskSchedulingType($fixture, 'Cure');
    $set = productionTaskSchedulingSet($fixture, $pour, $cure, [0, 1]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);

    app(SaveProductionHoliday::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Annual closure',
        date: '2026-08-17',
        isRecurring: true,
    );

    $production = productionTaskSchedulingPlan($fixture, '2027-08-17');
    $tasks = $production->tasks()->orderBy('id')->get();

    expect($tasks[0]->scheduled_for->toDateString())->toBe('2027-08-17')
        ->and($tasks[1]->scheduled_for->toDateString())->toBe('2027-08-18');
});

it('keeps production and the first task synchronized in both directions', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $set = productionTaskSchedulingSet($fixture, $pour, null, [0]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $first = $production->tasks()->orderBy('id')->firstOrFail();

    app(RescheduleProduction::class)->handle($fixture['owner'], $production, '2026-08-12');
    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-12')
        ->and($first->fresh()->scheduled_for->toDateString())->toBe('2026-08-12');

    app(RescheduleProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $first->fresh(),
        scheduledFor: '2026-08-14',
    );

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-14')
        ->and($first->fresh()->scheduled_for->toDateString())->toBe('2026-08-14')
        ->and($first->fresh()->scheduling_mode)->toBe('automatic');
});

it('marks later dates custom, resets them, and leaves completed tasks stable', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $cure = productionTaskSchedulingType($fixture, 'Cure');
    $set = productionTaskSchedulingSet($fixture, $pour, $cure, [0, 2]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $tasks = $production->tasks()->orderBy('id')->get();

    app(RescheduleProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $tasks[1],
        scheduledFor: '2026-08-20',
    );
    app(RescheduleProduction::class)->handle($fixture['owner'], $production, '2026-08-12');

    expect($tasks[0]->fresh()->scheduled_for->toDateString())->toBe('2026-08-12')
        ->and($tasks[1]->fresh()->scheduled_for->toDateString())->toBe('2026-08-20')
        ->and($tasks[1]->fresh()->scheduling_mode)->toBe('custom');

    app(ResetProductionTaskDate::class)->handle($fixture['owner'], $tasks[1]->fresh());

    expect($tasks[1]->fresh()->scheduled_for->toDateString())->toBe('2026-08-14')
        ->and($tasks[1]->fresh()->scheduling_mode)->toBe('automatic');

    app(CompleteProductionTask::class)->handle($fixture['owner'], $tasks[1]->fresh());
    app(ReopenProductionTask::class)->handle($fixture['owner'], $tasks[1]->fresh());

    expect($tasks[1]->fresh()->completed_at)->toBeNull();
});

it('does not move a completed automatic task when the production date changes', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $cure = productionTaskSchedulingType($fixture, 'Cure', 60);
    $set = productionTaskSchedulingSet($fixture, $pour, $cure, [0, 2]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $tasks = $production->tasks()->orderBy('id')->get();
    $completedDate = $tasks[1]->scheduled_for->toDateString();

    app(CompleteProductionTask::class)->handle($fixture['owner'], $tasks[1]);
    app(RescheduleProduction::class)->handle($fixture['owner'], $production, '2026-08-12');

    expect($tasks[0]->fresh()->scheduled_for->toDateString())->toBe('2026-08-12')
        ->and($tasks[1]->fresh()->scheduled_for->toDateString())->toBe($completedDate)
        ->and($tasks[1]->fresh()->completed_at)->not->toBeNull();
});

it('accepts an active employee and preserves a later deactivated assignment', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $employee = app(SaveEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        firstName: 'Ana',
        lastName: 'Maker',
    );
    $pour = productionTaskSchedulingType($fixture, 'Pour');
    $set = productionTaskSchedulingSet($fixture, $pour, null, [0]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $task = $production->tasks()->firstOrFail();

    app(RescheduleProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task,
        employee: $employee,
    );
    $employee->update(['is_active' => false]);

    expect($task->fresh()->employee_id)->toBe($employee->id);

    $foreign = productionTaskSchedulingFixture();
    $foreignEmployee = app(SaveEmployee::class)->handle(
        actor: $foreign['owner'],
        workspace: $foreign['workspace'],
        firstName: 'Foreign',
        lastName: 'Maker',
    );

    expect(fn (): ProductionTask => app(RescheduleProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task->fresh(),
        employee: $foreignEmployee,
    ))->toThrow(ValidationException::class);
});

it('does not rewrite generated snapshots when a task template changes', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour', 30);
    $set = productionTaskSchedulingSet($fixture, $pour, null, [0]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $task = $production->tasks()->firstOrFail();

    $pour->update(['name' => 'Renamed pour', 'default_duration_minutes' => 90]);
    $set->items()->firstOrFail()->update(['days_after_production' => 5]);

    expect($task->fresh()->name_snapshot)->toBe('Pour')
        ->and($task->fresh()->duration_minutes)->toBe(30)
        ->and($task->fresh()->days_after_production)->toBe(0);
});

it('rejects task date mutations after production starts', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour');
    $set = productionTaskSchedulingSet($fixture, $pour, null, [0]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');
    $task = $production->tasks()->firstOrFail();
    $production->update(['status' => ProductionRunStatus::InProduction]);

    expect(fn (): ProductionRun => app(RescheduleProduction::class)->handle(
        $fixture['owner'],
        $production,
        '2026-08-12',
    ))->toThrow(ValidationException::class)
        ->and(fn (): ProductionTask => app(RescheduleProductionTask::class)->handle(
            actor: $fixture['owner'],
            task: $task,
            scheduledFor: '2026-08-12',
        ))->toThrow(ValidationException::class);
});

it('does not duplicate tasks when generation is retried', function (): void {
    $fixture = productionTaskSchedulingFixture();
    $pour = productionTaskSchedulingType($fixture, 'Pour');
    $set = productionTaskSchedulingSet($fixture, $pour, null, [0]);
    $fixture['recipe']->update(['default_production_task_set_id' => $set->id]);
    $production = productionTaskSchedulingPlan($fixture, '2026-08-10');

    app(GenerateProductionTasks::class)->handle($fixture['owner'], $production->fresh());

    expect($production->tasks()->count())->toBe(1);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionTaskSchedulingFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'production_works_on_weekends' => false,
    ]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'task-schedule-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'batch_mass_grams' => '1000.000000000',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}

/** @param array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} $fixture */
function productionTaskSchedulingType(array $fixture, string $name, ?int $duration = null): ProductionTaskType
{
    return app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: $name,
        defaultDurationMinutes: $duration,
    );
}

/** @param array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} $fixture */
function productionTaskSchedulingSet(array $fixture, ProductionTaskType $first, ?ProductionTaskType $second, array $offsets): ProductionTaskSet
{
    $items = [['task_type_id' => $first->id, 'days_after_production' => $offsets[0]]];

    if ($second instanceof ProductionTaskType) {
        $items[] = ['task_type_id' => $second->id, 'days_after_production' => $offsets[1]];
    }

    return app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Soap workflow',
        items: $items,
    );
}

/** @param array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} $fixture */
function productionTaskSchedulingPlan(array $fixture, string $date): ProductionRun
{
    return app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe']->fresh(),
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: fake()->uuid(),
        plannedFor: $date,
    );
}
