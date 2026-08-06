<?php

use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionTaskType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes a persistent departments settings route and setup navigation', function (): void {
    $fixture = productionDepartmentPagesFixture();

    $response = $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.departments'));

    $response->assertOk()
        ->assertSee('id="department-heading"', false)
        ->assertSee('Departments')
        ->assertSee('Employees')
        ->assertSee('Task types')
        ->assertSee('Task sets')
        ->assertSee('Working calendar');
});

it('creates and edits departments from the settings component', function (): void {
    $fixture = productionDepartmentPagesFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('section', 'departments')
        ->set('departmentName', '  Finishing  ')
        ->call('saveDepartment')
        ->assertHasNoErrors();

    $department = Department::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($department->name)->toBe('Finishing')
        ->and($department->normalized_name)->toBe('finishing');

    $page->set('departmentName', 'Quality')
        ->set('departmentIsActive', false)
        ->call('editDepartment', $department->id)
        ->set('departmentName', 'Quality')
        ->set('departmentIsActive', false)
        ->call('saveDepartment')
        ->assertHasNoErrors();

    expect($department->fresh()->name)->toBe('Quality')
        ->and($department->fresh()->is_active)->toBeFalse();
});

it('saves employee titles and multiple active department memberships', function (): void {
    $fixture = productionDepartmentPagesFixture();
    $production = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
    ]);
    $quality = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Quality',
        'normalized_name' => 'quality',
    ]);

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('section', 'employees')
        ->set('employeeFirstName', 'Ana')
        ->set('employeeLastName', 'Maker')
        ->set('employeeTitle', 'Workshop lead')
        ->set('employeeDepartmentIds', [$production->id, $quality->id])
        ->call('saveEmployee')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($employee->title)->toBe('Workshop lead')
        ->and($employee->departments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$production->id, $quality->id])->sort()->values()->all());
});

it('saves an optional default department on a task type', function (): void {
    $fixture = productionDepartmentPagesFixture();
    $department = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Packing',
        'normalized_name' => 'packing',
    ]);

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('section', 'task-types')
        ->set('taskTypeName', 'Pack')
        ->set('taskTypeDepartmentId', $department->id)
        ->call('saveTaskType')
        ->assertHasNoErrors();

    $taskType = ProductionTaskType::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($taskType->department_id)->toBe($department->id);
});

it('deletes unused departments and preserves used records through the UI', function (): void {
    $fixture = productionDepartmentPagesFixture();
    $department = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Unused',
        'normalized_name' => 'unused',
    ]);

    Livewire::actingAs($fixture['owner'])->test(SettingsIndex::class)
        ->set('section', 'departments')
        ->call('deleteDepartment', $department->id)
        ->assertHasNoErrors();

    expect(Department::query()->find($department->id))->toBeNull();
});

/** @return array{owner: User, workspace: Workspace} */
function productionDepartmentPagesFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return compact('owner', 'workspace');
}
