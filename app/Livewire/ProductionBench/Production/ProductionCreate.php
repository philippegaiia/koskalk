<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\PlanProduction;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunSource;
use App\Services\Production\ProductionAvailabilityPreview;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionCreate extends Component
{
    public string $recipeId = '';

    public string $presetId = '';

    public string $taskSetId = '';

    public string $basisInputValue = '';

    public string $basisInputUnit = 'kg';

    public string $expectedUnits = '';

    public string $plannedFor = '';

    public string $notes = '';

    public string $idempotencyKey = '';

    public ?string $savedPublicId = null;

    public function mount(): void
    {
        $this->basisInputUnit = $this->workspace()->mass_display_system->priceUnit()->value;
        $this->plannedFor = now()->toDateString();
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function updatedRecipeId(): void
    {
        $this->resetValidation();
        $recipe = $this->selectedRecipe();

        if (! $recipe instanceof Recipe) {
            $this->reset(['presetId', 'taskSetId', 'basisInputValue', 'expectedUnits']);

            return;
        }

        $preset = ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('recipe_id', $recipe->id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($preset instanceof ProductionBatchPreset) {
            $this->presetId = (string) $preset->id;
            $this->basisInputValue = $this->displayDecimal((string) $preset->basis_input_value);
            $this->basisInputUnit = $preset->basis_input_unit->value;
            $this->expectedUnits = (string) $preset->expected_units;
        } else {
            $this->reset(['presetId', 'basisInputValue', 'expectedUnits']);
        }

        $taskSet = $recipe->defaultProductionTaskSet;
        $this->taskSetId = $taskSet instanceof ProductionTaskSet && $taskSet->is_active
            ? (string) $taskSet->id
            : '';
    }

    public function updatedPresetId(): void
    {
        $preset = $this->selectedPreset();

        if (! $preset instanceof ProductionBatchPreset) {
            return;
        }

        $this->basisInputValue = $this->displayDecimal((string) $preset->basis_input_value);
        $this->basisInputUnit = $preset->basis_input_unit->value;
        $this->expectedUnits = (string) $preset->expected_units;
    }

    public function plan(PlanProduction $planProduction): void
    {
        $this->validate([
            'recipeId' => ['required', 'integer'],
            'basisInputValue' => ['required', 'numeric', 'gt:0'],
            'basisInputUnit' => ['required', 'in:g,kg,oz,lb'],
            'expectedUnits' => ['required', 'integer', 'min:1'],
            'plannedFor' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $recipe = $this->selectedRecipe();

            if (! $recipe instanceof Recipe) {
                throw ValidationException::withMessages([
                    'recipeId' => __('production_bench.production.select_product'),
                ]);
            }

            $production = $planProduction->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                recipe: $recipe,
                basisInputValue: $this->basisInputValue,
                basisInputUnit: $this->basisInputUnit,
                expectedUnits: $this->expectedUnits,
                idempotencyKey: $this->idempotencyKey,
                plannedFor: $this->plannedFor,
                notes: filled($this->notes) ? $this->notes : null,
                source: ProductionRunSource::Direct,
                taskSet: $this->selectedTaskSet(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field === 'recipe' ? 'recipeId' : $field, $message);
                }
            }

            return;
        }

        $this->savedPublicId = $production->public_id;
        $this->idempotencyKey = (string) Str::uuid();
        $this->dispatch('production-planned');
    }

    public function render(
        ProductionBenchAccess $access,
        ProductionAvailabilityPreview $availabilityPreview,
    ): View {
        $workspace = $this->workspace();
        $recipe = $this->selectedRecipe();
        $taskSet = $this->selectedTaskSet();

        return view('livewire.production-bench.production.production-create', [
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'recipes' => Recipe::query()
                ->where('workspace_id', $workspace->id)
                ->whereHas('publishedVersions')
                ->with('productFamily')
                ->orderBy('name')
                ->get(),
            'presets' => ProductionBatchPreset::query()
                ->where('workspace_id', $workspace->id)
                ->when($recipe instanceof Recipe, fn ($query) => $query->where('recipe_id', $recipe->id))
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'taskSets' => ProductionTaskSet::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with('items.taskType')
                ->orderBy('name')
                ->get(),
            'preview' => $availabilityPreview->for(
                workspace: $workspace,
                recipe: $recipe,
                basisInputValue: $this->basisInputValue,
                basisInputUnit: $this->basisInputUnit,
                expectedUnits: $this->expectedUnits,
                taskSet: $taskSet,
                plannedFor: $this->plannedFor,
            ),
        ]);
    }

    private function selectedRecipe(): ?Recipe
    {
        if ($this->recipeId === '') {
            return null;
        }

        return Recipe::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace()->id)
            ->whereHas('publishedVersions')
            ->with('productFamily', 'defaultProductionTaskSet')
            ->find((int) $this->recipeId);
    }

    private function selectedPreset(): ?ProductionBatchPreset
    {
        if ($this->presetId === '' || $this->recipeId === '') {
            return null;
        }

        return ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('recipe_id', (int) $this->recipeId)
            ->where('is_active', true)
            ->find((int) $this->presetId);
    }

    private function selectedTaskSet(): ?ProductionTaskSet
    {
        if ($this->taskSetId === '') {
            return null;
        }

        return ProductionTaskSet::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->find((int) $this->taskSetId);
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }

    private function displayDecimal(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
