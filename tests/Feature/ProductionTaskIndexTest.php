<?php

use App\Livewire\ProductionBench\Production\TaskIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('routes the static task list before the production detail wildcard', function (): void {
    $fixture = productionTaskIndexFixture();
    $task = productionTaskIndexTask($fixture, 'Make batch', today()->toDateString());

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.tasks'))
        ->assertOk()
        ->assertSee('id="production-task-list-heading"', false)
        ->assertSee($task->name_snapshot);
});

it('shows today and upcoming/overdue/completed scopes with combined filters', function (): void {
    $fixture = productionTaskIndexFixture();
    $today = productionTaskIndexTask($fixture, 'Today task', today()->toDateString());
    $upcoming = productionTaskIndexTask($fixture, 'Upcoming task', today()->addDay()->toDateString());
    $overdue = productionTaskIndexTask($fixture, 'Overdue task', today()->subDay()->toDateString());
    $completed = productionTaskIndexTask($fixture, 'Completed task', today()->toDateString(), completed: true);
    $department = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Quality',
        'normalized_name' => 'quality',
    ]);
    $employee = Employee::factory()->for($fixture['workspace'])->create([
        'first_name' => 'Ana',
        'last_name' => 'Maker',
    ]);
    $today->update(['department_id' => $department->id, 'employee_id' => $employee->id]);

    $page = Livewire::actingAs($fixture['owner'])->test(TaskIndex::class);

    $page->assertSee('Today task')
        ->assertDontSee('Upcoming task')
        ->assertDontSee('Overdue task')
        ->assertDontSee('Completed task')
        ->set('scope', 'upcoming')
        ->assertSee('Upcoming task')
        ->assertDontSee('Today task')
        ->set('scope', 'overdue')
        ->assertSee('Overdue task')
        ->set('scope', 'completed')
        ->assertSee('Completed task')
        ->set('scope', 'all')
        ->set('status', 'all')
        ->set('departmentId', (string) $department->id)
        ->assertSee('Today task')
        ->assertDontSee('Upcoming task')
        ->set('employeeId', (string) $employee->id)
        ->assertSee('Today task');
});

it('keeps task search and assignment options inside the active workspace', function (): void {
    $fixture = productionTaskIndexFixture();
    $other = productionTaskIndexFixture();
    $local = productionTaskIndexTask($fixture, 'Local pour', today()->toDateString());
    $foreign = productionTaskIndexTask($other, 'Foreign pour', today()->toDateString());
    $localDepartment = Department::factory()->for($fixture['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
    ]);
    $foreignDepartment = Department::factory()->for($other['workspace'])->create([
        'name' => 'Production',
        'normalized_name' => 'production',
    ]);

    $page = Livewire::actingAs($fixture['owner'])->test(TaskIndex::class)
        ->set('scope', 'all')
        ->set('status', 'all')
        ->set('search', 'pour');

    $page->assertSee('Local pour')
        ->assertDontSee('Foreign pour')
        ->assertSee($localDepartment->name)
        ->assertDontSeeHtml('value="'.$foreignDepartment->id.'"');

    expect($local->workspace_id)->toBe($fixture['workspace']->id)
        ->and($foreign->workspace_id)->toBe($other['workspace']->id);
});

it('searches retained tasks by their production name snapshot', function (): void {
    $fixture = productionTaskIndexFixture();
    $task = productionTaskIndexTask($fixture, 'Historical pour', today()->toDateString());
    $task->productionRun->forceFill([
        'recipe_id' => null,
        'recipe_name_snapshot' => 'Archived soap',
    ])->save();

    Livewire::actingAs($fixture['owner'])->test(TaskIndex::class)
        ->set('scope', 'all')
        ->set('status', 'all')
        ->set('search', 'Archived soap')
        ->assertSee('Historical pour');
});

/** @return array{owner: User, workspace: Workspace} */
function productionTaskIndexFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return compact('owner', 'workspace');
}

/** @param array{owner: User, workspace: Workspace} $fixture */
function productionTaskIndexTask(array $fixture, string $name, string $date, bool $completed = false): ProductionTask
{
    $production = ProductionRun::factory()->for($fixture['workspace'])->create([
        'planned_for' => $date,
    ]);

    return ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($production, 'productionRun')
        ->create([
            'name_snapshot' => $name,
            'scheduled_for' => $date,
            'completed_at' => $completed ? now() : null,
        ]);
}
