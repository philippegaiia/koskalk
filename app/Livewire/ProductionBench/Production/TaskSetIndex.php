<?php

namespace App\Livewire\ProductionBench\Production;

use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionTaskSet;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class TaskSetIndex extends Component
{
    use InteractsWithAppNotifications;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    use WithPagination;

    public string $search = '';

    public string $status = 'active';

    public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [25, 50, 100], true)) {
            $this->perPage = 25;
        }

        $this->resetPage();
    }

    public function delete(int $taskSetId, ProductionBenchAccess $access): void
    {
        $workspace = $this->workspace();
        $access->assertWritable($this->user(), $workspace);

        ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($taskSetId)
            ->delete();

        $this->resetPage();
        $this->showAppNotification(__('production_bench.settings.task_set_deleted'));
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim($this->search);
        $searchTerm = '%'.Str::lower($search).'%';
        $status = in_array($this->status, ['active', 'all', 'inactive'], true) ? $this->status : 'active';

        $taskSets = ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->with(['items.taskType', 'recipes:id,name'])
            ->withCount(['recipes', 'items'])
            ->when($search !== '', function ($query) use ($searchTerm): void {
                $query->where(function ($searchQuery) use ($searchTerm): void {
                    $searchQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                        ->orWhereHas('items.taskType', fn ($taskTypeQuery) => $taskTypeQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('recipes', fn ($recipeQuery) => $recipeQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]));
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.production-bench.production.task-set-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'taskSets' => $taskSets,
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
