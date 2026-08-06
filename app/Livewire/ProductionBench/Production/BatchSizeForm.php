<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveProductionBatchPreset;
use App\Actions\Production\SyncProductionBatchPresetProducts;
use App\MassUnit;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class BatchSizeForm extends Component
{
    use WithPagination;

    public string|ProductionBatchPreset|null $preset = null;

    public string $name = '';

    public string $basisInputValue = '';

    public string $basisInputUnit = 'kg';

    public string $expectedUnits = '';

    public bool $isActive = true;

    public string $productSearch = '';

    public int $perPage = 25;

    /** @var list<string|int> */
    public array $selectedRecipeIds = [];

    /** @var list<string|int> */
    public array $defaultRecipeIds = [];

    public function mount(string|ProductionBatchPreset|null $preset = null): void
    {
        if ($preset === null) {
            $this->basisInputUnit = $this->workspace()->mass_display_system->priceUnit()->value;

            return;
        }

        $presetPublicId = $preset instanceof ProductionBatchPreset ? $preset->public_id : $preset;
        $loadedPreset = ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $presetPublicId)
            ->with('recipes')
            ->firstOrFail();

        $this->preset = $loadedPreset;
        $this->name = $loadedPreset->name;
        $this->basisInputValue = NumberLocale::formatAdaptiveDecimal(
            $loadedPreset->basis_input_value,
            0,
            3,
            $this->user()->number_locale,
        );
        $this->basisInputUnit = $loadedPreset->basis_input_unit->value;
        $this->expectedUnits = (string) $loadedPreset->expected_units;
        $this->isActive = $loadedPreset->is_active;
        $activeRecipes = $loadedPreset->recipes
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

    public function save(
        SaveProductionBatchPreset $savePreset,
        SyncProductionBatchPresetProducts $syncProducts,
        ProductionBenchAccess $access,
    ): void {
        $this->basisInputValue = NumberLocale::normalizeDecimalString($this->basisInputValue) ?? $this->basisInputValue;

        $this->validate(
            [
                'name' => ['required', 'string', 'max:120'],
                'basisInputValue' => ['required', 'numeric', 'gt:0'],
                'basisInputUnit' => ['required', Rule::in(array_map(fn (MassUnit $unit): string => $unit->value, MassUnit::cases()))],
                'expectedUnits' => ['required', 'integer', 'min:1'],
                'selectedRecipeIds' => ['array'],
                'selectedRecipeIds.*' => ['integer'],
                'defaultRecipeIds' => ['array'],
                'defaultRecipeIds.*' => ['integer'],
                'isActive' => ['boolean'],
            ],
            [
                'basisInputValue.required' => __('production_bench.settings.batch_size_required'),
                'basisInputValue.numeric' => __('production_bench.settings.batch_size_invalid'),
                'basisInputValue.gt' => __('production_bench.settings.batch_size_invalid'),
                'expectedUnits.required' => __('production_bench.settings.expected_units_required'),
                'expectedUnits.integer' => __('production_bench.settings.expected_units_whole'),
                'expectedUnits.min' => __('production_bench.settings.expected_units_positive'),
            ],
            [
                'basisInputValue' => __('production_bench.settings.batch_size'),
                'expectedUnits' => __('production_bench.settings.expected_units'),
            ],
        );

        try {
            $access->assertWritable($this->user(), $this->workspace());
            $currentPreset = $this->preset instanceof ProductionBatchPreset ? $this->preset : null;
            $savedPreset = $savePreset->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->name,
                basisInputValue: $this->basisInputValue,
                basisInputUnit: $this->basisInputUnit,
                expectedUnits: $this->expectedUnits,
                isActive: $this->isActive,
                preset: $currentPreset,
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
                preset: $savedPreset,
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
                        'basis_input_value' => 'basisInputValue',
                        'basis_input_unit' => 'basisInputUnit',
                        'expected_units' => 'expectedUnits',
                        'recipes' => 'selectedRecipeIds',
                        default => $field,
                    }, $message);
                }
            }

            return;
        }

        session()->flash('status', __('production_bench.settings.batch_size_saved'));
        $this->redirectRoute('production-bench.production.settings.presets', navigate: true);
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim($this->productSearch);

        $recipes = Recipe::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->whereHas('publishedVersions')
            ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.production-bench.production.batch-size-form', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'massUnits' => MassUnit::cases(),
            'recipes' => $recipes,
            'editing' => $this->preset instanceof ProductionBatchPreset,
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
