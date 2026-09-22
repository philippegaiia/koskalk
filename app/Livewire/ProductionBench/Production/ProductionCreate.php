<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\PlanProduction;
use App\Actions\Production\SaveProductProductionLocation;
use App\Enums\ProductionRunSource;
use App\Enums\ProductionRunStatus;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionLocation;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\Production\ProductionAvailabilityPreview;
use App\Services\Production\ProductionDailyOccupancy;
use App\Services\Production\ProductionLocationSelection;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionCreate extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;

    public string $recipeId = '';

    public string $presetId = '';

    public string $taskSetId = '';

    public string $basisInputValue = '';

    public string $basisInputUnit = 'kg';

    public string $expectedUnits = '';

    public ?string $plannedFor = '';

    public string $productionLocationId = '';

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
            $this->reset(['presetId', 'taskSetId', 'basisInputValue', 'expectedUnits', 'productionLocationId']);

            return;
        }

        $this->productionLocationId = $this->workspace()->uses_production_locations
            ? (string) (app(ProductionLocationSelection::class)->defaultFor($this->workspace(), $recipe) ?? '')
            : '';

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

    public function saveProductProductionLocation(SaveProductProductionLocation $saveProductProductionLocation): void
    {
        try {
            $recipe = $this->selectedRecipe();

            if (! $recipe instanceof Recipe) {
                throw ValidationException::withMessages([
                    'recipeId' => __('production_bench.production.select_product'),
                ]);
            }

            $savedRecipe = $saveProductProductionLocation->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                recipe: $recipe,
                locationId: $this->selectedProductionLocationId(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field === 'production_location_id' ? 'productionLocationId' : $field, $message);
                }
            }

            return;
        }

        $this->productionLocationId = (string) ($savedRecipe->default_production_location_id ?? '');
        $this->showAppNotification(__('locations.saved'));
        $this->dispatch('production-location-default-updated');
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
                plannedFor: $this->plannedFor ?? '',
                notes: filled($this->notes) ? $this->notes : null,
                source: ProductionRunSource::Direct,
                taskSet: $this->selectedTaskSet(),
                productionLocationId: $this->selectedProductionLocationId(),
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
                productionLocationId: $this->selectedProductionLocationId(),
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
        ProductionHelpTopics $helpTopics,
        ProductionBenchAccess $access,
        ProductionAvailabilityPreview $availabilityPreview,
        ProductionDailyOccupancy $occupancy,
    ): View {
        $workspace = $this->workspace();
        $recipe = $this->selectedRecipe();
        $taskSet = $this->selectedTaskSet();
        $productionLocations = $workspace->uses_production_locations
            ? ProductionLocation::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.production-bench.production.production-create', [
            'contextualHelp' => $helpTopics->resolve('create', app()->getLocale()),
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'productionLocations' => $productionLocations,
            'capacityWarnings' => $this->capacityWarnings(
                workspace: $workspace,
                plannedFor: $this->plannedFor ?? '',
                locationId: $this->selectedProductionLocationId(),
                productionLocations: $productionLocations,
                occupancy: $occupancy,
            ),
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
                plannedFor: $this->plannedFor ?? '',
            ),
        ]);
    }

    /**
     * @param  Collection<int, ProductionLocation>  $productionLocations
     * @return list<array{label: string, count: int, limit: int}>
     */
    private function capacityWarnings(
        Workspace $workspace,
        string $plannedFor,
        ?int $locationId,
        Collection $productionLocations,
        ProductionDailyOccupancy $occupancy,
    ): array {
        if (! $this->isDate($plannedFor)) {
            return [];
        }

        $occupied = $occupancy->between($workspace, $plannedFor, $plannedFor.' 23:59:59');
        $warnings = [];
        $overallCount = ($occupied['overall'][$plannedFor] ?? 0) + 1;

        if ($overallCount > (int) $workspace->production_daily_limit) {
            $warnings[] = [
                'label' => __('locations.daily_production_limit'),
                'count' => $overallCount,
                'limit' => (int) $workspace->production_daily_limit,
            ];
        }

        if (! $workspace->uses_production_locations) {
            return $warnings;
        }

        $location = $locationId === null
            ? null
            : $productionLocations->firstWhere('id', $locationId);

        if ($location instanceof ProductionLocation) {
            $locationCount = ($occupied['locations'][$location->id][$plannedFor] ?? 0) + 1;

            if ($locationCount > $location->daily_production_limit) {
                $warnings[] = [
                    'label' => $location->name,
                    'count' => $locationCount,
                    'limit' => (int) $location->daily_production_limit,
                ];
            }
        }

        return $warnings;
    }

    private function selectedProductionLocationId(): ?int
    {
        if (! $this->workspace()->uses_production_locations || trim($this->productionLocationId) === '') {
            return null;
        }

        return ctype_digit(trim($this->productionLocationId))
            ? (int) $this->productionLocationId
            : 0;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
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

    public function updated(string $property): void
    {
        if ($property !== 'plannedFor') {
            return;
        }

        $value = $this->plannedFor ?? '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) === 1 ? substr($value, 0, 10) : '';
        $this->plannedFor = validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes() ? $date : '';
    }

    public function planningDateForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('plannedFor')
                ->label(__('production_bench.production.production_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->live()
                ->required()
                ->disabled(! app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace())),
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

    private function displayDecimal(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
