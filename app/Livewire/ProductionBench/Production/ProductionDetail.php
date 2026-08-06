<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\AssignProductionTask;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\ReopenProductionTask;
use App\Actions\Production\RescheduleProductionTask;
use App\Actions\Production\ResetProductionTaskDate;
use App\Actions\Production\SaveProductionActuals;
use App\Actions\Production\StartProduction;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\StockReservationStatus;
use App\WorkspaceMemberRole;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionDetail extends Component
{
    use InteractsWithAppNotifications;

    public string $productionId = '';

    public string $cancellationReason = '';

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    /** @var array<string, array{stock_lot_id?: int|null, quantity: string, note?: string|null}> */
    public array $actualRows = [];

    public bool $actualsDirty = false;

    public string $outputMode = 'units';

    public string $actualOutputQuantity = '';

    public string $manufactureDate = '';

    public ?string $outputIngredientId = null;

    public string $abortReason = '';

    public function assignBatchNumber(AssignProductionBatchNumbers $assignProductionBatchNumbers): void
    {
        try {
            $assignProductionBatchNumbers->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                productionIds: [(int) $this->productionId],
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        in_array($field, ['production_ids', 'batch_number', 'next_permanent_serial'], true)
                            ? 'production_bench'
                            : $field,
                        $message,
                    );
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.batch_number_assigned'));
        $this->dispatch('production-batch-numbers-updated');
    }

    public function assignTask(int $taskId, ?string $employeeId, AssignProductionTask $assignProductionTask): void
    {
        try {
            $task = $this->task($taskId);

            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: filled($employeeId) ? (int) $employeeId : null,
                departmentId: $task->department_id,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function assignTaskDepartment(int $taskId, ?string $departmentId, AssignProductionTask $assignProductionTask): void
    {
        try {
            $task = $this->task($taskId);

            $assignProductionTask->handle(
                actor: $this->user(),
                task: $task,
                employeeId: $task->employee_id,
                departmentId: filled($departmentId) ? (int) $departmentId : null,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
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
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function rescheduleTask(int $taskId, string $scheduledFor, RescheduleProductionTask $rescheduleProductionTask): void
    {
        try {
            $rescheduleProductionTask->handle(
                actor: $this->user(),
                task: $this->task($taskId),
                scheduledFor: $scheduledFor,
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function resetTaskDate(int $taskId, ResetProductionTaskDate $resetProductionTaskDate): void
    {
        try {
            $resetProductionTaskDate->handle($this->user(), $this->task($taskId));
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-task-updated');
    }

    public function mount(string|int|ProductionRun $productionId): void
    {
        if ($productionId instanceof ProductionRun) {
            $this->productionId = (string) $productionId->id;

            return;
        }

        if (is_numeric($productionId)) {
            $this->productionId = (string) $productionId;

            return;
        }

        $this->productionId = (string) (ProductionRun::query()
            ->where('public_id', $productionId)
            ->value('id') ?? abort(404));
    }

    public function cancel(CancelProduction $cancelProduction): void
    {
        try {
            $cancelProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->cancellationReason,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(in_array($field, ['production', 'production_bench'], true) ? 'cancellationReason' : $field, $message);
                }
            }

            return;
        }

        $this->cancellationReason = '';
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-cancelled');
    }

    public function start(StartProduction $startProduction): void
    {
        try {
            $startProduction->handle(
                actor: $this->user(),
                production: $this->production(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(in_array($field, ['production', 'production_bench'], true) ? 'production' : $field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.started'));
        $this->dispatch('production-started');
    }

    public function releaseStock(ReleaseProductionStock $releaseProductionStock): void
    {
        try {
            $releaseProductionStock->handle(
                actor: $this->user(),
                production: $this->production(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-stock-released');
    }

    public function updatedActualRows(): void
    {
        $this->actualsDirty = true;
    }

    public function saveActuals(SaveProductionActuals $saveProductionActuals): void
    {
        $production = $this->production();

        try {
            $rows = [];

            foreach ($this->actualRows as $requirementId => $row) {
                $rows[] = [
                    'production_requirement_id' => (int) $requirementId,
                    'stock_lot_id' => isset($row['stock_lot_id']) && $row['stock_lot_id'] !== '' && $row['stock_lot_id'] !== null
                        ? (int) $row['stock_lot_id']
                        : null,
                    'quantity' => (string) ($row['quantity'] ?? '0'),
                    'note' => isset($row['note']) && $row['note'] !== '' ? $row['note'] : null,
                ];
            }

            $saveProductionActuals->handle($this->user(), $production, $rows);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('actuals', $message);
                }
            }

            return;
        }

        $this->actualRows = $this->actualRowsFromProduction($production->fresh(['requirements', 'consumption']));
        $this->actualsDirty = false;
        $this->showAppNotification(__('production_bench.production.actuals_saved'));
        $this->dispatch('production-actuals-saved');
    }

    public function complete(CompleteProduction $completeProduction): void
    {
        $production = $this->production();

        try {
            $completeProduction->handle(
                actor: $this->user(),
                production: $production,
                actualOutputQuantity: $this->actualOutputQuantity,
                manufactureDate: $this->manufactureDate !== '' ? $this->manufactureDate : now()->toDateString(),
                outputIngredientId: $this->outputMode === 'intermediate' && $this->outputIngredientId !== null
                    ? (int) $this->outputIngredientId
                    : null,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.completed'));
        $this->dispatch('production-completed');
    }

    public function abort(AbortProduction $abortProduction): void
    {
        try {
            $abortProduction->handle(
                actor: $this->user(),
                production: $this->production(),
                reason: $this->abortReason,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.aborted'));
        $this->dispatch('production-aborted');
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $production = $this->production();
        $canMutate = $access->isActive($workspace)
            && ! $access->isReadOnly($workspace)
            && in_array($workspace->roleFor($this->user()), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);

        $defaultActualRows = [];

        foreach ($production->requirements as $requirement) {
            if (isset($this->actualRows[(string) $requirement->id])) {
                continue;
            }

            $reservedQuantity = '0';
            $firstLotId = null;

            foreach ($requirement->reservations->where('status', StockReservationStatus::Active) as $reservation) {
                $reservedQuantity = bcadd($reservedQuantity, (string) $reservation->quantity, 9);
                $firstLotId ??= $reservation->stock_lot_id;
            }

            $defaultActualRows[(string) $requirement->id] = [
                'stock_lot_id' => $firstLotId,
                'quantity' => $reservedQuantity,
                'note' => null,
            ];
        }

        return view('livewire.production-bench.production.production-detail', [
            'workspace' => $workspace,
            'production' => $production,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'defaultActualRows' => $defaultActualRows,
            'intermediateIngredients' => Ingredient::query()
                ->withoutGlobalScopes()
                ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
                ->orderBy('display_name')
                ->get(['id', 'display_name']),
            'employees' => Employee::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'departments' => Department::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function task(int $taskId): ProductionTask
    {
        return ProductionTask::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($taskId);
    }

    private function addTaskErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('task_'.$field, $message);
            }
        }
    }

    private function production(): ProductionRun
    {
        return ProductionRun::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with(['recipe', 'requirements.reservations', 'formulaLines', 'consumption', 'tasks.employee', 'tasks.department', 'cancelledBy', 'batchNumberAssignedBy'])
            ->findOrFail((int) $this->productionId);
    }

    /**
     * @return array<string, array{stock_lot_id: int|null, quantity: string, note: string|null}>
     */
    private function actualRowsFromProduction(ProductionRun $production): array
    {
        $rows = [];

        foreach ($production->consumption as $consumption) {
            $rows[(string) $consumption->production_requirement_id] = [
                'stock_lot_id' => $consumption->stock_lot_id,
                'quantity' => (string) $consumption->quantity,
                'note' => $consumption->note,
            ];
        }

        return $rows;
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
