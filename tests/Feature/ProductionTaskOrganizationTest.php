<?php

use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\PlanProduction;
use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Actions\Production\SyncProductionTaskSetProducts;
use App\Enums\OwnerType;
use App\Enums\ProductionRunStatus;
use App\Enums\Visibility;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductFamily;
use App\Models\ProductionTask;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('copies a task type default department into each generated task snapshot', function (): void {
    $fixture = productionTaskOrganizationFixture();
    $productionDepartment = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
    ]);
    $qualityDepartment = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Quality',
        'normalized_name' => 'quality',
    ]);
    $taskType = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
        departmentId: $productionDepartment->id,
    );
    $taskSet = app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Soap workflow',
        items: [['task_type_id' => $taskType->id, 'days_after_production' => 0]],
    );

    app(SyncProductionTaskSetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        taskSet: $taskSet,
        assignments: [['recipe_id' => $fixture['recipe']->id, 'is_default' => true]],
    );

    $firstProduction = app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'organization-first',
        plannedFor: '2026-08-10',
        taskSet: $taskSet,
    );

    app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
        departmentId: $qualityDepartment->id,
        taskType: $taskType,
    );

    $secondProduction = app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'organization-second',
        plannedFor: '2026-08-11',
        taskSet: $taskSet,
    );

    expect($firstProduction->tasks()->firstOrFail()->department_id)->toBe($productionDepartment->id)
        ->and($secondProduction->tasks()->firstOrFail()->department_id)->toBe($qualityDepartment->id)
        ->and($firstProduction->tasks()->firstOrFail()->fresh()->department_id)->toBe($productionDepartment->id);
});

it('assigns departments and employees independently while production is in progress', function (): void {
    $fixture = productionTaskOrganizationFixture();
    $productionDepartment = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
    ]);
    $qualityDepartment = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Quality',
        'normalized_name' => 'quality',
    ]);
    $employee = Employee::factory()->for($fixture['workspace'])->create(['is_active' => true]);
    $task = productionTaskOrganizationProduction($fixture, $productionDepartment);

    app(AssignProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task,
        departmentId: $qualityDepartment->id,
        employeeId: $employee->id,
    );
    $task->productionRun->update(['status' => ProductionRunStatus::InProduction]);

    app(AssignProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task->fresh(),
        departmentId: $productionDepartment->id,
        employeeId: null,
    );

    expect($task->fresh()->department_id)->toBe($productionDepartment->id)
        ->and($task->fresh()->employee_id)->toBeNull();

    $task->productionRun->update(['status' => ProductionRunStatus::Completed]);

    expect(fn (): ProductionTask => app(AssignProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task->fresh(),
        departmentId: $qualityDepartment->id,
        employeeId: $employee->id,
    ))->toThrow(ValidationException::class);
});

it('rejects inactive and cross-workspace assignment options', function (): void {
    $fixture = productionTaskOrganizationFixture();
    $department = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
        'is_active' => false,
    ]);
    $employee = Employee::factory()->for($fixture['workspace'])->create(['is_active' => false]);
    $task = productionTaskOrganizationProduction($fixture);

    expect(fn (): ProductionTask => app(AssignProductionTask::class)->handle(
        actor: $fixture['owner'],
        task: $task,
        departmentId: $department->id,
    ))->toThrow(ValidationException::class)
        ->and(fn (): ProductionTask => app(AssignProductionTask::class)->handle(
            actor: $fixture['owner'],
            task: $task,
            employeeId: $employee->id,
        ))->toThrow(ValidationException::class);
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} */
function productionTaskOrganizationFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'production_works_on_weekends' => false,
    ]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'task-organization-'.fake()->unique()->numberBetween(1, 999999),
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
        'manufacturing_mode' => 'blend_only',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}

/** @param array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} $fixture */
function productionTaskOrganizationProduction(array $fixture, ?Department $department = null): ProductionTask
{
    $taskType = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
        departmentId: $department?->id,
    );
    $taskSet = app(SaveProductionTaskSet::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Soap workflow',
        items: [['task_type_id' => $taskType->id, 'days_after_production' => 0]],
    );
    app(SyncProductionTaskSetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        taskSet: $taskSet,
        assignments: [['recipe_id' => $fixture['recipe']->id, 'is_default' => true]],
    );

    return app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: fake()->uuid(),
        plannedFor: '2026-08-10',
        taskSet: $taskSet,
    )->tasks()->firstOrFail();
}
