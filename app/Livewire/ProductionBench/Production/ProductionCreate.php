<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\PlanProduction;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunSource;
use App\ProductionRunStatus;
use App\Services\Production\ProductionAvailabilityPreview;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionCreate extends Component
{
    use InteractsWithAppNotifications;

    public string $recipeId = '';

    public string $presetId = '';

    public string $taskSetId = '';

    public string $basisInputValue = '';

    public string $basisInputUnit = 'kg';

    public string $expectedUnits = '';

    public string $plannedFor = '';

    public string $notes = '';

    public string $idempotencyKey = '';

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(): void
    {
        $this->basisInputUnit = $this->workspace()->mass_display_system->priceUnit()->value;
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

        $presets = $recipe->productionBatchPresets()
            ->where('is_active', true)
            ->get();
        $preset = $recipe->defaultProductionBatchPresets()
            ->where('is_active', true)
            ->first();
        $preset ??= $presets->count() === 1 ? $presets->first() : null;

        if ($preset instanceof ProductionBatchPreset) {
            $this->presetId = (string) $preset->id;
            $this->basisInputValue = $this->displayDecimal((string) $preset->basis_input_value);
            $this->basisInputUnit = $preset->basis_input_unit->value;
            $this->expectedUnits = (string) $preset->expected_units;
        } else {
            $this->reset(['presetId', 'basisInputValue', 'expectedUnits']);
        }

        $taskSets = $recipe->productionTaskSets()
            ->where('is_active', true)
            ->get();
        $taskSet = $recipe->defaultProductionTaskSetModel();

        if (! $taskSet instanceof ProductionTaskSet || ! $taskSet->is_active) {
            $taskSet = $taskSets->count() === 1 ? $taskSets->first() : null;
        }

        $this->taskSetId = $taskSet instanceof ProductionTaskSet ? (string) $taskSet->id : '';
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
        $this->basisInputValue = NumberLocale::normalizeDecimalString($this->basisInputValue) ?? $this->basisInputValue;

        $this->validate([
            'recipeId' => ['required', 'integer'],
            'basisInputValue' => ['required', 'numeric', 'gt:0'],
            'basisInputUnit' => ['required', 'in:g,kg,oz,lb'],
            'expectedUnits' => ['required', 'integer', 'min:1'],
            'plannedFor' => ['nullable', 'date_format:Y-m-d'],
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
                plannedFor: filled($this->plannedFor) ? $this->plannedFor : null,
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

        $this->idempotencyKey = (string) Str::uuid();
        $this->showAppNotification(__('production_bench.production.planned_success').' '.$production->public_id);
        $this->dispatch('production-planned');
    }

    /**
     * Save a draft production without a date. Drafts have no stock
     * effect and no generated tasks — they are scheduling placeholders.
     */
    public function saveDraft(CreateProductionDraft $createProductionDraft): void
    {
        $this->basisInputValue = NumberLocale::normalizeDecimalString($this->basisInputValue) ?? $this->basisInputValue;

        $this->validate([
            'recipeId' => ['required', 'integer'],
            'basisInputValue' => ['required', 'numeric', 'gt:0'],
            'basisInputUnit' => ['required', 'in:g,kg,oz,lb'],
            'expectedUnits' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $recipe = $this->selectedRecipe();

            if (! $recipe instanceof Recipe) {
                throw ValidationException::withMessages([
                    'recipeId' => __('production_bench.production.select_product'),
                ]);
            }

            $production = $createProductionDraft->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                recipe: $recipe,
                basisInputValue: $this->basisInputValue,
                basisInputUnit: $this->basisInputUnit,
                expectedUnits: $this->expectedUnits,
                idempotencyKey: $this->idempotencyKey,
                plannedFor: null,
                notes: filled($this->notes) ? $this->notes : null,
                source: ProductionRunSource::Direct,
                status: ProductionRunStatus::Draft,
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

        $this->idempotencyKey = (string) Str::uuid();
        $this->showAppNotification(__('production_bench.production.draft_saved').' '.$production->public_id);
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
                ->whereNull('archived_at')
                ->whereHas('publishedVersions')
                ->with('productFamily')
                ->orderBy('name')
                ->get(),
            'presets' => $recipe instanceof Recipe
                ? $recipe->productionBatchPresets()
                    ->where('is_active', true)
                    ->orderByDesc('production_batch_preset_recipe.is_default')
                    ->get()
                : collect(),
            'taskSets' => $recipe instanceof Recipe
                ? $recipe->productionTaskSets()
                    ->where('is_active', true)
                    ->with('items.taskType')
                    ->get()
                : collect(),
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
            ->whereNull('archived_at')
            ->whereHas('publishedVersions')
            ->with('productFamily', 'productionTaskSets', 'productionBatchPresets')
            ->find((int) $this->recipeId);
    }

    private function selectedPreset(): ?ProductionBatchPreset
    {
        if ($this->presetId === '' || $this->recipeId === '') {
            return null;
        }

        return ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->whereHas('recipes', fn ($query) => $query->whereKey((int) $this->recipeId))
            ->find((int) $this->presetId);
    }

    private function selectedTaskSet(): ?ProductionTaskSet
    {
        if ($this->taskSetId === '') {
            return null;
        }

        $recipe = $this->selectedRecipe();

        if (! $recipe instanceof Recipe) {
            return null;
        }

        return $recipe->productionTaskSets()
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
