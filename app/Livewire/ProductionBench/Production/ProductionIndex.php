<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\WorkspaceMemberRole;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class ProductionIndex extends Component
{
    use InteractsWithAppNotifications;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var list<int> */
    public array $selectedProductionIds = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function prepareSelected(): void
    {
        $this->selectedProductionIds = array_values(array_filter(
            array_map('intval', $this->selectedProductionIds),
            fn (int $id): bool => $id > 0,
        ));

        if ($this->selectedProductionIds === []) {
            $this->addError('selectedProductionIds', __('production_bench.production.select_production_to_prepare'));

            return;
        }

        $this->redirectRoute('production-bench.production.prepare', [
            'ids' => implode(',', $this->selectedProductionIds),
        ]);
    }

    public function assignSelectedBatchNumbers(AssignProductionBatchNumbers $assignProductionBatchNumbers): void
    {
        try {
            $result = $assignProductionBatchNumbers->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                productionIds: $this->selectedProductionIds,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        in_array($field, ['production_ids', 'batch_number', 'production_bench'], true)
                            ? 'selectedProductionIds'
                            : $field,
                        $message,
                    );
                }
            }

            return;
        }

        $this->selectedProductionIds = [];
        $this->showAppNotification(__('production_bench.production.batch_numbers_assigned', [
            'assigned' => $result['assigned'],
            'already' => $result['already_assigned'],
        ]));
        $this->dispatch('production-batch-numbers-updated');
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $canMutate = $access->isActive($workspace)
            && ! $access->isReadOnly($workspace)
            && in_array($workspace->roleFor($this->user()), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);
        $productions = ProductionRun::query()
            ->where('workspace_id', $workspace->id)
            ->with(['recipe', 'tasks'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('public_id', 'like', "%{$search}%")
                        ->orWhere('planning_batch_number', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%")
                        ->orWhereHas('recipe', fn (Builder $recipe): Builder => $recipe->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->dateFrom !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '<=', $this->dateTo))
            ->orderByRaw('planned_for is null')
            ->orderBy('planned_for')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.production-bench.production.production-index', [
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'productions' => $productions,
        ]);
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
