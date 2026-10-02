<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\ReopenProductionTask;
use App\Livewire\Concerns\InteractsWithProductionWorkspace;
use App\Livewire\Concerns\NormalizesDatePickerState;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\Production\ProductionEditingContext;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class TaskIndex extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithProductionWorkspace;
    use NormalizesDatePickerState;
    use WithPagination;

    public string $scope = 'today';

    public string $status = 'open';

    #[Locked]
    public array $displayedProductionRevisions = [];

    #[Locked]
    public array $displayedTaskProductionIds = [];

    public string $search = '';

    public string $departmentId = '';

    public string $employeeId = '';

    public string $fromDate = '';

    public string $toDate = '';

    public int $perPage = 25;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 25;
        }

        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedScope(): void
    {
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedDepartmentId(): void
    {
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedEmployeeId(): void
    {
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->fromDate = $this->normalizeDatePickerState($this->fromDate);

        if ($this->fromDate !== '' && $this->toDate !== '' && $this->fromDate > $this->toDate) {
            $this->toDate = $this->fromDate;
        }

        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->toDate = $this->normalizeDatePickerState($this->toDate);

        if ($this->fromDate !== '' && $this->toDate !== '' && $this->toDate < $this->fromDate) {
            $this->fromDate = $this->toDate;
        }

        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function filterDatesForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('fromDate')
                ->label(__('production_bench.production.from_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->maxDate(fn (Get $get): ?string => filled($get('toDate')) ? $this->normalizeDatePickerState((string) $get('toDate')) : null)
                ->live(),
            DatePicker::make('toDate')
                ->label(__('production_bench.production.to_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->minDate(fn (Get $get): ?string => filled($get('fromDate')) ? $this->normalizeDatePickerState((string) $get('fromDate')) : null)
                ->live(),
        ]);
    }

    public function clearFilters(): void
    {
        $this->reset(['scope', 'status', 'search', 'departmentId', 'employeeId', 'fromDate', 'toDate']);
        $this->scope = 'today';
        $this->status = 'open';
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
        $this->resetPage();
    }

    public function toggleTask(int $taskId, CompleteProductionTask $completeProductionTask, ReopenProductionTask $reopenProductionTask): void
    {
        $task = $this->task($taskId);

        $this->resetErrorBag();
        try {
            $context = $this->registerEditingContext([$task->production_run_id]);
            if ($task->completed_at === null) {
                $completeProductionTask->handle($this->user(), $task, editing: $context);
            } else {
                $reopenProductionTask->handle($this->user(), $task, editing: $context);
            }
            $this->displayedProductionRevisions = array_replace($this->displayedProductionRevisions, $context->acknowledgedRevisions());
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function assignDepartment(int $taskId, ?string $departmentId, AssignProductionTask $assignProductionTask): void
    {
        $task = $this->task($taskId);

        $this->resetErrorBag();
        try {
            $context = $this->registerEditingContext([$task->production_run_id]);
            $assignProductionTask->handle(
                editing: $context,
                actor: $this->user(),
                task: $task,
                departmentId: filled($departmentId) ? (int) $departmentId : null,
                employeeId: $task->employee_id,
            );
            $this->displayedProductionRevisions = array_replace($this->displayedProductionRevisions, $context->acknowledgedRevisions());
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function assignEmployee(int $taskId, ?string $employeeId, AssignProductionTask $assignProductionTask): void
    {
        $task = $this->task($taskId);

        $this->resetErrorBag();
        try {
            $context = $this->registerEditingContext([$task->production_run_id]);
            $assignProductionTask->handle(
                editing: $context,
                actor: $this->user(),
                task: $task,
                departmentId: $task->department_id,
                employeeId: filled($employeeId) ? (int) $employeeId : null,
            );
            $this->displayedProductionRevisions = array_replace($this->displayedProductionRevisions, $context->acknowledgedRevisions());
        } catch (ValidationException $exception) {
            $this->addActionErrors($exception);
        }
    }

    public function render(ProductionHelpTopics $helpTopics, ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $searchOperator = ProductionTask::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = ProductionTask::query()
            ->where('workspace_id', $workspace->id)
            ->with(['productionRun', 'department', 'employee'])
            ->when($this->search !== '', function (Builder $query) use ($searchOperator): void {
                $search = trim($this->search);
                $query->where(function (Builder $query) use ($search, $searchOperator): void {
                    $query->where('name_snapshot', $searchOperator, '%'.$search.'%')
                        ->orWhereHas('productionRun', function (Builder $query) use ($search, $searchOperator): void {
                            $query->where('public_id', $searchOperator, '%'.$search.'%')
                                ->orWhere('recipe_name_snapshot', $searchOperator, '%'.$search.'%');
                        });
                });
            })
            ->when($this->departmentId !== '', fn (Builder $query): Builder => $query->where('department_id', (int) $this->departmentId))
            ->when($this->employeeId !== '', fn (Builder $query): Builder => $query->where('employee_id', (int) $this->employeeId))
            ->when($this->status === 'open' && $this->scope !== 'completed', fn (Builder $query): Builder => $query->whereNull('completed_at'))
            ->when($this->status === 'completed', fn (Builder $query): Builder => $query->whereNotNull('completed_at'));

        $this->applyDateScope($query);

        $tasks = $query->orderBy('scheduled_for')->orderBy('id')->paginate($this->perPage);
        if ($this->displayedProductionRevisions === []) {
            $this->displayedTaskProductionIds = $tasks->getCollection()->mapWithKeys(fn (ProductionTask $task): array => [$task->id => $task->production_run_id])->all();
            $this->displayedProductionRevisions = $tasks->getCollection()->mapWithKeys(fn (ProductionTask $task): array => [$task->production_run_id => $task->productionRun->edit_revision])->all();
        }

        return view('livewire.production-bench.production.task-index', [
            'contextualHelp' => $helpTopics->resolve('tasks', app()->getLocale()),
            'workspace' => $workspace,
            'tasks' => $tasks,
            'departments' => Department::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'employees' => Employee::query()->where('workspace_id', $workspace->id)->orderBy('last_name')->orderBy('first_name')->get(),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
        ]);
    }

    public function updatedPaginators(): void
    {
        $this->refreshProductionRegister();
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
        abort_unless(isset($this->displayedTaskProductionIds[$taskId]), 422);

        return ProductionTask::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('production_run_id', $this->displayedTaskProductionIds[$taskId])
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

    public function refreshProductionRegister(): void
    {
        $this->resetErrorBag();
        $this->displayedProductionRevisions = [];
        $this->displayedTaskProductionIds = [];
    }

    /** @param list<int> $ids */
    private function registerEditingContext(array $ids): ProductionEditingContext
    {
        if ($ids === [] || count($ids) > 100 || array_diff($ids, array_keys($this->displayedProductionRevisions)) !== []) {
            throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
        }

        return new ProductionEditingContext($this->workspace()->id, (string) Str::uuid(), array_intersect_key($this->displayedProductionRevisions, array_flip($ids)), temporary: true);
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->productionWorkspace();
    }
}
