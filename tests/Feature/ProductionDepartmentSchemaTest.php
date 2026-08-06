<?php

use App\Actions\Production\DeleteDepartment;
use App\Actions\Production\DeleteEmployee;
use App\Actions\Production\SaveDepartment;
use App\Actions\Production\SyncEmployeeDepartments;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\ProductionTaskType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores normalized workspace departments and supports many-to-many employee membership', function (): void {
    $fixture = productionDepartmentFixture();
    $other = productionDepartmentFixture();

    $department = app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: '  Finishing  ',
    );
    $otherDepartment = app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Quality',
    );
    $employee = Employee::factory()->for($fixture['workspace'])->create();

    app(SyncEmployeeDepartments::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        employee: $employee,
        departmentIds: [$department->id, $otherDepartment->id, $department->id],
    );

    expect($department->name)->toBe('Finishing')
        ->and($department->normalized_name)->toBe('finishing')
        ->and($department->workspace->is($fixture['workspace']))->toBeTrue()
        ->and($employee->fresh()->departments)->toHaveCount(2)
        ->and($employee->fresh()->departments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$department->id, $otherDepartment->id])->sort()->values()->all());

    expect(fn (): Department => app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: ' finishing ',
    ))->toThrow(ValidationException::class);

    $foreignDepartment = app(SaveDepartment::class)->handle(
        actor: $other['owner'],
        workspace: $other['workspace'],
        name: 'Foreign',
    );

    expect(fn () => app(SyncEmployeeDepartments::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        employee: $employee,
        departmentIds: [$foreignDepartment->id],
    ))->toThrow(ValidationException::class);
});

it('allows optional inactive departments and keeps task relationships workspace-safe', function (): void {
    $fixture = productionDepartmentFixture();
    $department = app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Production',
        isActive: false,
    );
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create([
        'department_id' => $department->id,
    ]);
    $production = ProductionRun::factory()->for($fixture['workspace'])->create();
    $task = ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($production, 'productionRun')
        ->create(['department_id' => $department->id]);

    expect($department->is_active)->toBeFalse()
        ->and($taskType->fresh()->department->is($department))->toBeTrue()
        ->and($task->fresh()->department->is($department))->toBeTrue();

    expect(fn () => app(SyncEmployeeDepartments::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        employee: Employee::factory()->for($fixture['workspace'])->create(),
        departmentIds: [$department->id],
    ))->toThrow(ValidationException::class);
});

it('protects departments and employees that are referenced by production records', function (): void {
    $fixture = productionDepartmentFixture();
    $department = app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Production',
    );
    $employee = Employee::factory()->for($fixture['workspace'])->create();

    app(DeleteDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        department: $department,
    );

    expect(Department::query()->find($department->id))->toBeNull();

    $usedDepartment = app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Packing',
    );
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create([
        'department_id' => $usedDepartment->id,
    ]);
    $production = ProductionRun::factory()->for($fixture['workspace'])->create();
    $task = ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($production, 'productionRun')
        ->create([
            'department_id' => $usedDepartment->id,
            'employee_id' => $employee->id,
        ]);

    expect($taskType->fresh()->department->is($usedDepartment))->toBeTrue()
        ->and($task->fresh()->employee->is($employee))->toBeTrue();

    expect(fn () => app(DeleteDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        department: $usedDepartment,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(DeleteEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        employee: $employee,
    ))->toThrow(ValidationException::class);

    expect($employee->fresh()->is_active)->toBeTrue();
});

it('does not allow cross-workspace department or employee mutations', function (): void {
    $fixture = productionDepartmentFixture();
    $other = productionDepartmentFixture();
    $foreignDepartment = app(SaveDepartment::class)->handle(
        actor: $other['owner'],
        workspace: $other['workspace'],
        name: 'Foreign',
    );
    $foreignEmployee = Employee::factory()->for($other['workspace'])->create();

    expect(fn (): Department => app(SaveDepartment::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Updated',
        department: $foreignDepartment,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(DeleteEmployee::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        employee: $foreignEmployee,
    ))->toThrow(ValidationException::class);
});

/** @return array{owner: User, workspace: Workspace} */
function productionDepartmentFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return compact('owner', 'workspace');
}
