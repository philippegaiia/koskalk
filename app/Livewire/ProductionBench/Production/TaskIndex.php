<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\ReopenProductionTask;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class TaskIndex extends Component
{
    use WithPagination;

    public string $scope = 'today';

    public string $status = 'open';

    public string $search = '';

    public string $departmentId = '';

    public string $employeeId = '';

    public string $fromDate = '';

    public string $toDate = '';

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentId(): void
    {
        $this->resetPage();
    }

    public function updatedEmployeeId(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['scope', 'status', 'search', 'departmentId', 'employeeId', 'fromDate', 'toDate']);
        $this->scope = 'today';
        $this->status = 'open';
        $this->resetPage();
    }

    public function toggleTask(int $taskId, CompleteProductionTask $completeProductionTask, ReopenProductionTask $reopenProductionTask): void
    {
        $task = $this->task($taskId);

        try {
            if ($task->completed_at === null) {
                $completeProductionTask->handle($this->user(), $task);
            } else {
                $reopenProductionTask->handle($this->user(), $task);
            }
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function assignDepartment(int $taskId, ?string $departmentId, AssignProductionTask $assignProductionTask): void
    {
        $task = $this->task($taskId);

        try {
            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                departmentId: filled($departmentId) ? (int) $departmentId : null,
                employeeId: $task->employee_id,
            );
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function assignEmployee(int $taskId, ?string $employeeId, AssignProductionTask $assignProductionTask): void
    {
        $task = $this->task($taskId);

        try {
            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                departmentId: $task->department_id,
                employeeId: filled($employeeId) ? (int) $employeeId : null,
            );
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $searchOperator = ProductionTask::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = ProductionTask::query()
            ->where('workspace_id', $workspace->id)
            ->with(['productionRun.recipe', 'department', 'employee'])
            ->when($this->search !== '', function (Builder $query) use ($searchOperator): void {
                $search = trim($this->search);
                $query->where(function (Builder $query) use ($search, $searchOperator): void {
                    $query->where('name_snapshot', $searchOperator, '%'.$search.'%')
                        ->orWhereHas('productionRun', function (Builder $query) use ($search, $searchOperator): void {
                            $query->where('public_id', $searchOperator, '%'.$search.'%')
                                ->orWhereHas('recipe', fn (Builder $query) => $query->where('name', $searchOperator, '%'.$search.'%'));
                        });
                });
            })
            ->when($this->departmentId !== '', fn (Builder $query): Builder => $query->where('department_id', (int) $this->departmentId))
            ->when($this->employeeId !== '', fn (Builder $query): Builder => $query->where('employee_id', (int) $this->employeeId))
            ->when($this->status === 'open' && $this->scope !== 'completed', fn (Builder $query): Builder => $query->whereNull('completed_at'))
            ->when($this->status === 'completed', fn (Builder $query): Builder => $query->whereNotNull('completed_at'));

        $this->applyDateScope($query);

        return view('livewire.production-bench.production.task-index', [
            'workspace' => $workspace,
            'tasks' => $query->orderBy('scheduled_for')->orderBy('id')->paginate(25),
            'departments' => Department::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'employees' => Employee::query()->where('workspace_id', $workspace->id)->orderBy('last_name')->orderBy('first_name')->get(),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
        ]);
    }

    private function applyDateScope(Builder $query): void
    {
        $today = today()->toDateString();

        match ($this->scope) {
            'upcoming' => $query->whereDate('scheduled_for', '>', $today)->whereNull('completed_at'),
            'overdue' => $query->whereDate('scheduled_for', '<', $today)->whereNull('completed_at'),
            'completed' => $query->whereNotNull('completed_at'),
            'all' => null,
            default => $query->whereDate('scheduled_for', $today),
        };

        if ($this->fromDate !== '') {
            $query->whereDate('scheduled_for', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->whereDate('scheduled_for', '<=', $this->toDate);
        }
    }

    private function task(int $taskId): ProductionTask
    {
        return ProductionTask::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with('productionRun')
            ->findOrFail($taskId);
    }

    private function addActionErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('task_'.$field, $message);
            }
        }
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
