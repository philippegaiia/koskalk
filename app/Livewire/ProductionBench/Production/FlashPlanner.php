<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\GenerateFlashProductions;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashPlanFingerprint;
use App\Services\Production\FlashProductionSimulator;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FlashPlanner extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var list<array<string, string>> */
    public array $lines = [];

    public ?string $firstDate = '';

    public string $batchesPerDay = '1';

    public bool $showDatePreview = false;

    #[Locked]
    public string $idempotencyKey = '';

    #[Locked]
    public ?string $proposalFingerprint = null;

    /** @var list<array<string, mixed>> */
    public array $datePreview = [];

    /** @var array<string, mixed> */
    public array $simulationSnapshot = [];

    public ?string $simulationError = null;

    public function mount(): void
    {
        $this->firstDate = now()->toDateString();
        $this->batchesPerDay = (string) ($this->workspace()->production_daily_limit ?? 1);
        $this->lines = [$this->blankLine()];
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function updatedLines(mixed $value, string $key): void
    {
        $this->simulationError = null;
        $this->proposalFingerprint = null;
        $this->showDatePreview = false;
        $this->datePreview = [];
        $this->simulationSnapshot = [];

        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field === 'recipe_id' && is_numeric($index)) {
            $this->applyRecipeDefaults((int) $index);
        }

        if ($field === 'batch_mode' && is_numeric($index)) {
            $this->applyBatchMode((int) $index);
        }
    }

    public function addLine(): void
    {
        $this->simulationError = null;
        $this->lines[] = $this->blankLine();
        $this->proposalFingerprint = null;
        $this->showDatePreview = false;
        $this->simulationSnapshot = [];
    }

    public function removeLine(int $index): void
    {
        $this->simulationError = null;
        if (count($this->lines) === 1) {
            $this->lines = [$this->blankLine()];
        } else {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }

        $this->proposalFingerprint = null;
        $this->showDatePreview = false;
        $this->datePreview = [];
        $this->simulationSnapshot = [];
    }

    public function updatedFirstDate(): void
    {
        $this->simulationError = null;
        $this->proposalFingerprint = null;
        $this->showDatePreview = false;
        $this->datePreview = [];
    }

    public function updatedBatchesPerDay(): void
    {
        $this->updatedFirstDate();
    }

    public function previewDates(FlashProductionSimulator $simulator, FlashDateProposalService $dateProposal): void
    {
        $this->simulationError = null;
        $workspace = $this->workspace()->refresh();

        try {
            $this->simulationSnapshot = [];
            $simulation = $simulator->simulate($this->workspace(), $this->lines);
            $this->datePreview = $dateProposal->propose(
                workspace: $this->workspace(),
                lines: $simulation['lines'],
                firstDate: $this->firstDate,
                batchesPerDay: $this->positiveWhole($this->batchesPerDay),
            );
            $this->proposalFingerprint = app(FlashPlanFingerprint::class)->proposal($simulation, $this->datePreview, $this->workspace(), $this->positiveWhole($this->batchesPerDay));
            $this->showDatePreview = true;
        } catch (ValidationException $exception) {
            $this->simulationError = collect($exception->errors())->flatten()->first();
            $this->proposalFingerprint = null;
            $this->showDatePreview = false;
            $this->datePreview = [];
        }
    }

    public function generate(GenerateFlashProductions $generate): void
    {
        $this->simulationError = null;

        if ($this->proposalFingerprint === null) {
            $this->simulationError = __('locations.validation.preview_required');

            return;
        }

        try {
            $generate->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                lines: $this->lines,
                firstDate: $this->firstDate,
                batchesPerDay: $this->batchesPerDay,
                idempotencyKey: $this->idempotencyKey,
                expectedProposalFingerprint: $this->proposalFingerprint,
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('proposal', $exception->errors())) {
                $this->previewDates(app(FlashProductionSimulator::class), app(FlashDateProposalService::class));
            }
            $this->simulationError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->idempotencyKey = (string) Str::uuid();
        $this->proposalFingerprint = null;
        $this->showDatePreview = false;
        $this->dispatch('flash-productions-generated');
    }

    public function render(
        ProductionHelpTopics $helpTopics,
        FlashProductionSimulator $simulator,
        ProductionBenchAccess $access,
    ): View {
        $workspace = $this->workspace();
        $simulation = null;

        if ($this->hasEnteredLine()) {
            try {
                $simulation = $this->simulation($simulator, $workspace);
            } catch (ValidationException $exception) {
                $this->simulationError = collect($exception->errors())->flatten()->first();
            }
        }

        return view('livewire.production-bench.production.flash-planner', [
            'contextualHelp' => $helpTopics->resolve('flash', app()->getLocale()),
            'workspace' => $workspace,
            'productionLocations' => $workspace->uses_production_locations ? ProductionLocation::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('name')->get() : collect(),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'recipes' => Recipe::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('archived_at')
                ->whereHas('publishedVersions')
                ->orderBy('name')
                ->get(),
            'presets' => ProductionBatchPreset::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with('recipes')
                ->orderBy('name')
                ->get(),
            'taskSets' => ProductionTaskSet::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with('recipes')
                ->orderBy('name')
                ->get(),
            'simulation' => $simulation,
            'existingProductionsByDay' => $this->showDatePreview
                ? ProductionRun::query()->where('workspace_id', $workspace->id)
                    ->whereIn('planned_for', collect($this->datePreview)->pluck('production_date')->unique())
                    ->whereNotIn('status', [ProductionRunStatus::Draft, ProductionRunStatus::Cancelled])
                    ->orderBy('id')->get()->groupBy(fn ($run): string => $run->planned_for->toDateString())
                : collect(),
        ]);
    }

    /** @return array<string, string> */
    private function blankLine(): array
    {
        return [
            'recipe_id' => '',
            'production_location_id' => '',
            'preset_id' => '',
            'batch_mode' => 'custom',
            'task_set_id' => '',
            'basis_input_value' => '',
            'basis_input_unit' => $this->workspace()->mass_display_system->priceUnit()->value,
            'expected_units_per_batch' => '',
            'desired_units' => '',
        ];
    }

    private function applyRecipeDefaults(int $index): void
    {
        $recipeId = (int) ($this->lines[$index]['recipe_id'] ?? 0);

        if ($recipeId < 1) {
            $this->lines[$index]['production_location_id'] = '';

            return;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace()->id)
            ->whereNull('archived_at')
            ->with([
                'productionTaskSets' => fn ($query) => $query->where('is_active', true),
                'productionBatchPresets' => fn ($query) => $query->where('is_active', true),
            ])
            ->find($recipeId);
        $this->lines[$index]['production_location_id'] = $recipe instanceof Recipe ? (string) (app(ProductionLocationSelection::class)->defaultFor($this->workspace(), $recipe) ?? '') : '';
        $presets = $recipe instanceof Recipe ? $recipe->productionBatchPresets : collect();
        $preset = $presets->first(fn (ProductionBatchPreset $candidate): bool => (bool) $candidate->pivot?->is_default);
        $preset ??= $presets->count() === 1 ? $presets->first() : null;

        $this->lines[$index]['preset_id'] = '';
        $this->lines[$index]['basis_input_value'] = '';
        $this->lines[$index]['basis_input_unit'] = $this->workspace()->mass_display_system->priceUnit()->value;
        $this->lines[$index]['expected_units_per_batch'] = '';

        if ($preset instanceof ProductionBatchPreset) {
            $this->lines[$index]['preset_id'] = (string) $preset->id;
            $this->lines[$index]['batch_mode'] = (string) $preset->id;
            $this->lines[$index]['basis_input_value'] = $this->displayDecimal((string) $preset->basis_input_value);
            $this->lines[$index]['basis_input_unit'] = $preset->basis_input_unit->value;
            $this->lines[$index]['expected_units_per_batch'] = (string) $preset->expected_units;
        } elseif ($presets->isEmpty()) {
            $this->lines[$index]['batch_mode'] = 'custom';
        } else {
            $this->lines[$index]['batch_mode'] = '';
        }

        $taskSets = $recipe instanceof Recipe ? $recipe->productionTaskSets : collect();
        $taskSet = $taskSets->first(fn (ProductionTaskSet $candidate): bool => (bool) $candidate->pivot?->is_default);
        $taskSet ??= $taskSets->count() === 1 ? $taskSets->first() : null;

        if ($taskSet instanceof ProductionTaskSet && $taskSet->is_active) {
            $this->lines[$index]['task_set_id'] = (string) $taskSet->id;
        } else {
            $this->lines[$index]['task_set_id'] = '';
        }
    }

    private function applyBatchMode(int $index): void
    {
        $mode = (string) ($this->lines[$index]['batch_mode'] ?? '');

        if ($mode === 'custom') {
            $this->lines[$index]['preset_id'] = '';
            $this->lines[$index]['basis_input_value'] = '';
            $this->lines[$index]['basis_input_unit'] = $this->workspace()->mass_display_system->priceUnit()->value;
            $this->lines[$index]['expected_units_per_batch'] = '';

            return;
        }

        if (! is_numeric($mode) || (int) $mode < 1) {
            $this->lines[$index]['preset_id'] = '';

            return;
        }

        $this->lines[$index]['preset_id'] = (string) $mode;
        $this->applyPresetDefaults($index);
    }

    private function applyPresetDefaults(int $index): void
    {
        $presetId = (int) ($this->lines[$index]['preset_id'] ?? 0);

        if ($presetId < 1) {
            return;
        }

        $preset = ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->find($presetId);

        if (! $preset instanceof ProductionBatchPreset) {
            return;
        }

        $recipeId = (int) ($this->lines[$index]['recipe_id'] ?? 0);

        if ($recipeId < 1 || ! $preset->recipes()->whereKey($recipeId)->exists()) {
            $this->lines[$index]['preset_id'] = '';
            $this->lines[$index]['batch_mode'] = 'custom';

            return;
        }

        $this->lines[$index]['batch_mode'] = (string) $preset->id;
        $this->lines[$index]['basis_input_value'] = $this->displayDecimal((string) $preset->basis_input_value);
        $this->lines[$index]['basis_input_unit'] = $preset->basis_input_unit->value;
        $this->lines[$index]['expected_units_per_batch'] = (string) $preset->expected_units;
    }

    /** @return array<string, mixed> */
    private function simulation(FlashProductionSimulator $simulator, ?Workspace $workspace = null): array
    {
        if ($this->simulationSnapshot !== []) {
            return $this->simulationSnapshot;
        }

        $result = $simulator->simulate($workspace ?? $this->workspace(), $this->lines);
        $this->simulationSnapshot = [
            'lines' => array_map(fn (array $line): array => [
                'line_index' => $line['line_index'],
                'recipe_id' => $line['recipe_id'],
                'recipe_name' => (string) $line['recipe']->name,
                'production_location_id' => $line['production_location_id'],
                'whole_batches' => $line['whole_batches'],
                'task_set_id' => $line['task_set_id'],
                'output_ready_delay_days' => $line['output_ready_delay_days'],
                'task_items' => $line['task_set']?->items->map(fn ($item): array => [
                    'name' => $item->taskType?->name ?? (string) $item->taskType?->key ?? 'Task',
                    'days_after_production' => (int) $item->days_after_production,
                    'colour' => $item->taskType?->colour,
                    'duration_minutes' => $item->duration_minutes === null ? null : (int) $item->duration_minutes,
                ])->values()->all() ?? [],
            ], $result['lines']),
            'requirements' => array_map(fn (array $requirement): array => [
                'material_code' => $requirement['material_code'] ?? null,
                'subject_name' => $requirement['subject_name'],
                'required_display' => $requirement['required_display'],
                'display_unit' => $requirement['display_unit'],
                'display_unit_price' => $requirement['display_unit_price'],
                'price_currency' => $requirement['price_currency'],
                'estimated_cost' => $requirement['estimated_cost'],
            ], $result['requirements']),
            'totals' => $result['totals'],
        ];

        return $this->simulationSnapshot;
    }

    private function hasEnteredLine(): bool
    {
        return collect($this->lines)->contains(fn (array $line): bool => filled($line['recipe_id'] ?? null)
            && filled($line['desired_units'] ?? null)
            && filled($line['basis_input_value'] ?? null)
            && filled($line['basis_input_unit'] ?? null)
            && filled($line['expected_units_per_batch'] ?? null));
    }

    private function positiveWhole(string $value): int
    {
        if (preg_match('/^[1-9]\d*$/', trim($value)) !== 1 || (int) $value > 1000) {
            throw ValidationException::withMessages([
                'batchesPerDay' => __('locations.validation.production_daily_limit'),
            ]);
        }

        return (int) $value;
    }

    private function displayDecimal(string $value): string
    {
        return NumberLocale::formatAdaptiveDecimal($value, 0, 3, $this->user()->number_locale);
    }

    public function updated(string $property): void
    {
        if ($property !== 'firstDate') {
            return;
        }

        $value = $this->firstDate ?? '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) === 1 ? substr($value, 0, 10) : '';
        $this->firstDate = validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes() ? $date : '';
    }

    public function planningDateForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('firstDate')
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
}
