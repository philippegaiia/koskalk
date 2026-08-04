<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\CancelProduction;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\ReopenProductionTask;
use App\Actions\Production\RescheduleProductionTask;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionDetail extends Component
{
    public string $productionId = '';

    public string $cancellationReason = '';

    public function assignTask(int $taskId, ?string $employeeId, RescheduleProductionTask $rescheduleProductionTask): void
    {
        try {
            $employee = filled($employeeId)
                ? Employee::query()->where('workspace_id', $this->workspace()->id)->find((int) $employeeId)
                : null;
            $rescheduleProductionTask->handle(
                actor: $this->user(),
                task: $this->task($taskId),
                employee: $employee,
                clearEmployee: blank($employeeId),
            );
        } catch (ValidationException $exception) {
            $this->addTaskErrors($exception);

            return;
        }

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
        $this->dispatch('production-cancelled');
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

        $this->dispatch('production-stock-released');
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $production = $this->production();

        return view('livewire.production-bench.production.production-detail', [
            'workspace' => $workspace,
            'production' => $production,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'employees' => Employee::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
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
            ->with(['recipe', 'recipeVersion', 'requirements', 'tasks.employee', 'cancelledBy'])
            ->findOrFail((int) $this->productionId);
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
