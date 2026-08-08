<?php

use App\Actions\Production\SaveEmployee;
use App\Actions\Production\SaveProductionHoliday;
use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Actions\Production\SyncProductionTaskSetProducts;
use App\Actions\Production\UpdateProductionWorkingCalendar;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Employee;
use App\Models\ProductFamily;
use App\Models\ProductionHoliday;
use App\Models\ProductionTask;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionRunNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('saves lightweight employees and task types with optional setup fields', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();

    $employee = app(SaveEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        firstName: 'Ana',
        lastName: 'Maker',
    );
    $taskType = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Cut and pour',
        defaultDurationMinutes: 45,
        colour: '#8F5C38',
    );

    expect($employee->first_name)->toBe('Ana')
        ->and($employee->last_name)->toBe('Maker')
        ->and($employee->is_active)->toBeTrue()
        ->and($taskType->name)->toBe('Cut and pour')
        ->and($taskType->default_duration_minutes)->toBe(45)
        ->and($taskType->colour)->toBe('#8F5C38')
        ->and($taskType->is_active)->toBeTrue()
        ->and($employee->workspace->is($fixture['workspace']))->toBeTrue();

    $inactive = app(SaveEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        firstName: 'Ana',
        lastName: 'Maker',
        isActive: false,
        employee: $employee,
    );

    expect($inactive->is_active)->toBeFalse();
});

it('keeps task-set ordering and requires a production-day anchor', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();
    $anchor = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
    );
    $curing = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Cure',
        defaultDurationMinutes: 1440,
    );

    $taskSet = app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Soap task set',
        items: [
            ['task_type_id' => $curing->id, 'days_after_production' => -1, 'duration_minutes' => 1440],
            ['task_type_id' => $anchor->id, 'days_after_production' => 0, 'duration_minutes' => 60],
        ],
        recipe: $fixture['recipe'],
        isDefault: true,
    );

    $items = $taskSet->items;

    expect($taskSet)->toBeInstanceOf(ProductionTaskSet::class)
        ->and($items)->toHaveCount(2)
        ->and($items[0]->position)->toBe(1)
        ->and($items[0]->days_after_production)->toBe(-1)
        ->and($items[0]->production_task_type_id)->toBe($curing->id)
        ->and($items[1]->position)->toBe(2)
        ->and($items[1]->days_after_production)->toBe(0)
        ->and($fixture['recipe']->fresh()->defaultProductionTaskSets()->first()?->is($taskSet))->toBeTrue();
});

it('supports preparation offsets and reusable task sets across several products', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();
    $otherRecipe = Recipe::factory()->for($fixture['recipe']->productFamily, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
    ]);
    $prepare = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Prepare moulds',
    );
    $make = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
    );
    $cure = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Cure',
    );

    $taskSet = app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Soap workflow',
        items: [
            ['task_type_id' => $prepare->id, 'days_after_production' => -1],
            ['task_type_id' => $make->id, 'days_after_production' => 0],
            ['task_type_id' => $cure->id, 'days_after_production' => 28],
        ],
    );

    app(SyncProductionTaskSetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        taskSet: $taskSet,
        recipeIds: [$fixture['recipe']->id, $otherRecipe->id],
        defaultRecipeId: $fixture['recipe']->id,
    );

    $items = $taskSet->fresh()->items;

    expect($items->pluck('days_after_production')->all())->toBe([-1, 0, 28])
        ->and($taskSet->fresh()->recipes)->toHaveCount(2)
        ->and($taskSet->fresh()->defaultRecipes()->pluck('id')->all())->toBe([$fixture['recipe']->id]);
});

it('requires a production-day anchor in every task set', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();
    $prepare = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Prepare moulds',
    );

    expect(fn (): ProductionTaskSet => app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Preparation only',
        items: [['task_type_id' => $prepare->id, 'days_after_production' => -1]],
    ))->toThrow(ValidationException::class);
});

it('stores generated task snapshots and keeps them independent from templates', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();
    $taskType = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Pour',
        defaultDurationMinutes: 30,
    );
    $taskSet = app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Default set',
        items: [['task_type_id' => $taskType->id, 'days_after_production' => 0]],
    );
    $production = $fixture['recipe']->productionRuns()->create([
        'workspace_id' => $fixture['workspace']->id,
        'recipe_version_id' => $fixture['version']->id,
        'status' => 'draft',
        'source' => 'direct',
        'basis_kind' => 'oil_mass',
        'basis_quantity_grams' => '1000.000000000',
        'basis_input_value' => '1.000000000',
        'basis_input_unit' => 'kg',
        'expected_units' => 10,
        'planning_batch_number' => app(ProductionRunNumberService::class)
            ->allocatePlanningReference($fixture['workspace']),
        'idempotency_key' => 'schema-task-production',
        'created_by_user_id' => $fixture['owner']->id,
    ]);
    $task = ProductionTask::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $production->id,
        'production_task_set_id' => $taskSet->id,
        'production_task_set_item_id' => $taskSet->items->first()->id,
        'employee_id' => null,
        'name_snapshot' => 'Pour',
        'days_after_production' => 0,
        'duration_minutes' => 30,
        'scheduled_for' => '2026-08-10',
        'scheduling_mode' => 'automatic',
        'completed_at' => null,
    ]);

    expect($task->productionRun->is($production))->toBeTrue()
        ->and($task->taskSetItem->is($taskSet->items->first()))->toBeTrue()
        ->and($task->name_snapshot)->toBe('Pour')
        ->and($task->days_after_production)->toBe(0)
        ->and($task->scheduling_mode)->toBe('automatic')
        ->and($task->completed_at)->toBeNull();
});

it('saves recurring holidays and the workspace weekend preference', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();

    $holiday = app(SaveProductionHoliday::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Summer closure',
        date: '2026-08-15',
        isRecurring: true,
    );
    $workspace = app(UpdateProductionWorkingCalendar::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        worksOnWeekends: true,
    );

    expect($holiday)->toBeInstanceOf(ProductionHoliday::class)
        ->and($holiday->date?->toDateString())->toBe('2026-08-15')
        ->and($holiday->is_recurring)->toBeTrue()
        ->and($workspace->production_works_on_weekends)->toBeTrue();
});

it('rejects cross-workspace setup records and read-only mutations', function (): void {
    $fixture = productionTaskSchemaTask4Fixture();
    $other = productionTaskSchemaTask4Fixture();

    expect(fn (): Employee => app(SaveEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        firstName: 'Foreign',
        lastName: 'Worker',
        employee: Employee::factory()->for($other['workspace'])->create(),
    ))->toThrow(ValidationException::class);

    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    expect(fn (): ProductionTaskType => app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Blocked',
    ))->toThrow(ValidationException::class);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionTaskSchemaTask4Fixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'task-schema-soap-'.fake()->unique()->numberBetween(1, 999999),
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
