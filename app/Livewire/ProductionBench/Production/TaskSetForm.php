<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SyncProductionTaskSetProducts;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class TaskSetForm extends Component
{
    use WithPagination;

    public string|ProductionTaskSet|null $taskSet = null;

    public string $name = '';

    public bool $isActive = true;

    /** @var list<array{task_type_id: string, days_after_production: string, duration_minutes: string}> */
    public array $taskSetItems = [];

    public string $productSearch = '';

    public int $perPage = 25;

    /** @var list<string|int> */
    public array $selectedRecipeIds = [];

    /** @var list<string|int> */
    public array $defaultRecipeIds = [];

    public function mount(string|ProductionTaskSet|null $taskSet = null): void
    {
        $this->taskSetItems = [$this->emptyTaskSetItem()];

        if ($taskSet === null) {
            return;
        }

        $taskSetPublicId = $taskSet instanceof ProductionTaskSet ? $taskSet->public_id : $taskSet;
        $loadedTaskSet = ProductionTaskSet::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $taskSetPublicId)
            ->with(['items.taskType', 'recipes'])
            ->firstOrFail();

        $this->taskSet = $loadedTaskSet;
        $this->name = $loadedTaskSet->name;
        $this->isActive = $loadedTaskSet->is_active;
        $this->taskSetItems = $loadedTaskSet->items
            ->map(fn ($item): array => [
                'task_type_id' => (string) $item->production_task_type_id,
                'days_after_production' => (string) $item->days_after_production,
                'duration_minutes' => $item->duration_minutes === null ? '' : (string) $item->duration_minutes,
            ])
            ->values()
            ->all();
        $activeRecipes = $loadedTaskSet->recipes
            ->filter(fn (Recipe $recipe): bool => $recipe->archived_at === null);
        $this->selectedRecipeIds = $activeRecipes
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->all();
        $this->defaultRecipeIds = $activeRecipes
            ->filter(fn (Recipe $recipe): bool => (bool) $recipe->pivot->is_default)
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function updatedProductSearch(): void
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

    /** @param list<string|int> $recipeIds */
    public function updatedSelectedRecipeIds(array $recipeIds): void
    {
        $selectedRecipeIds = $this->normalizeRecipeIds($recipeIds);
        $selectedLookup = array_fill_keys($selectedRecipeIds, true);

        $this->selectedRecipeIds = $selectedRecipeIds;
        $this->defaultRecipeIds = array_values(array_filter(
            $this->normalizeRecipeIds($this->defaultRecipeIds),
            fn (string $recipeId): bool => isset($selectedLookup[$recipeId]),
        ));
    }

    /** @param list<string|int> $recipeIds */
    public function updatedDefaultRecipeIds(array $recipeIds): void
    {
        $defaultRecipeIds = $this->normalizeRecipeIds($recipeIds);

        $this->defaultRecipeIds = $defaultRecipeIds;
        $this->selectedRecipeIds = $this->normalizeRecipeIds([
            ...$this->selectedRecipeIds,
            ...$defaultRecipeIds,
        ]);
    }

    public function addTaskSetItem(): void
    {
        $this->taskSetItems[] = $this->emptyTaskSetItem();
    }

    public function removeTaskSetItem(int $index): void
    {
        if (count($this->taskSetItems) <= 1) {
            return;
        }

        unset($this->taskSetItems[$index]);
        $this->taskSetItems = array_values($this->taskSetItems);
    }

    public function save(
        SaveProductionTaskSet $saveTaskSet,
        SyncProductionTaskSetProducts $syncProducts,
        ProductionBenchAccess $access,
    ): void {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'taskSetItems' => ['required', 'array', 'min:1'],
            'taskSetItems.*.task_type_id' => ['required', 'integer'],
            'taskSetItems.*.days_after_production' => ['required', 'integer'],
            'taskSetItems.*.duration_minutes' => ['nullable', 'integer', 'min:0'],
            'selectedRecipeIds' => ['array'],
            'selectedRecipeIds.*' => ['integer'],
            'defaultRecipeIds' => ['array'],
            'defaultRecipeIds.*' => ['integer'],
            'isActive' => ['boolean'],
        ], [
            'taskSetItems.*.task_type_id.required' => __('production_bench.settings.task_required'),
            'taskSetItems.*.days_after_production.required' => __('production_bench.settings.task_offset_required'),
            'taskSetItems.*.days_after_production.integer' => __('production_bench.settings.task_offset_whole'),
            'taskSetItems.*.duration_minutes.integer' => __('production_bench.settings.task_duration_whole'),
            'taskSetItems.*.duration_minutes.min' => __('production_bench.settings.task_duration_positive'),
        ]);

        if (! collect($this->taskSetItems)->contains(
            fn (array $item): bool => (string) ($item['days_after_production'] ?? '') === '0',
        )) {
            $this->addError('taskSetItems', __('production_bench.settings.task_set_production_day_required'));

            return;
        }

        try {
            $access->assertWritable($this->user(), $this->workspace());
            $currentTaskSet = $this->taskSet instanceof ProductionTaskSet ? $this->taskSet : null;
            $savedTaskSet = $saveTaskSet->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->name,
                items: collect($this->taskSetItems)->map(fn (array $item): array => [
                    'task_type_id' => $item['task_type_id'],
                    'days_after_production' => $item['days_after_production'],
                    'duration_minutes' => filled($item['duration_minutes'] ?? null)
                        ? $item['duration_minutes']
                        : null,
                ])->all(),
                isActive: $this->isActive,
                taskSet: $currentTaskSet,
            );

            $selectedRecipeIds = collect($this->selectedRecipeIds)
                ->merge($this->defaultRecipeIds)
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            $defaultRecipeIds = collect($this->defaultRecipeIds)
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique();

            $syncProducts->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                taskSet: $savedTaskSet,
                assignments: $selectedRecipeIds->map(fn (int $recipeId): array => [
                    'recipe_id' => $recipeId,
                    'is_default' => $defaultRecipeIds->contains($recipeId),
                ])->all(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(match ($field) {
                        'name' => 'name',
                        'items' => 'taskSetItems',
                        'recipes' => 'selectedRecipeIds',
                        default => $field,
                    }, $message);
                }
            }

            return;
        }

        session()->flash('status', __('production_bench.settings.task_set_saved'));
        $this->redirectRoute('production-bench.production.settings.task-sets', navigate: true);
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim($this->productSearch);
        $selectedTaskTypeIds = collect($this->taskSetItems)
            ->pluck('task_type_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $taskTypes = ProductionTaskType::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($selectedTaskTypeIds): void {
                $query->where('is_active', true);

                if ($selectedTaskTypeIds !== []) {
                    $query->orWhereIn('id', $selectedTaskTypeIds);
                }
            })
            ->orderBy('name')
            ->get();
        $recipes = Recipe::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->whereHas('publishedVersions')
            ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.production-bench.production.task-set-form', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'taskTypes' => $taskTypes,
            'recipes' => $recipes,
            'editing' => $this->taskSet instanceof ProductionTaskSet,
        ]);
    }

    private function emptyTaskSetItem(): array
    {
        return [
            'task_type_id' => '',
            'days_after_production' => '0',
            'duration_minutes' => '',
        ];
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }

    /**
     * @param  list<string|int>  $recipeIds
     * @return list<string>
     */
    private function normalizeRecipeIds(array $recipeIds): array
    {
        return collect($recipeIds)
            ->map(fn (int|string $id): string => (string) $id)
            ->filter(fn (string $id): bool => ctype_digit($id) && (int) $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
