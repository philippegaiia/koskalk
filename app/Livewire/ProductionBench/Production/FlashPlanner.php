<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\GenerateFlashProductions;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashProductionSimulator;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class FlashPlanner extends Component
{
    /** @var list<array<string, string>> */
    public array $lines = [];

    public string $firstDate = '';

    public string $batchesPerDay = '1';

    public bool $showDatePreview = false;

    public string $idempotencyKey = '';

    /** @var list<array<string, mixed>> */
    public array $datePreview = [];

    public ?string $simulationError = null;

    /** @var list<string> */
    public array $generatedPublicIds = [];

    public function mount(): void
    {
        $this->firstDate = now()->toDateString();
        $this->lines = [$this->blankLine()];
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function updatedLines(mixed $value, string $key): void
    {
        $this->simulationError = null;
        $this->showDatePreview = false;
        $this->datePreview = [];

        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field === 'recipe_id' && is_numeric($index)) {
            $this->applyRecipeDefaults((int) $index);
        }

        if ($field === 'preset_id' && is_numeric($index)) {
            $this->applyPresetDefaults((int) $index);
        }
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
        $this->showDatePreview = false;
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) === 1) {
            $this->lines = [$this->blankLine()];
        } else {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }

        $this->showDatePreview = false;
        $this->datePreview = [];
    }

    public function previewDates(FlashProductionSimulator $simulator, FlashDateProposalService $dateProposal): void
    {
        $this->simulationError = null;

        try {
            $simulation = $simulator->simulate($this->workspace(), $this->lines);
            $this->datePreview = $dateProposal->propose(
                workspace: $this->workspace(),
                lines: $simulation['lines'],
                firstDate: $this->firstDate,
                batchesPerDay: $this->positiveWhole($this->batchesPerDay),
            );
            $this->showDatePreview = true;
        } catch (ValidationException $exception) {
            $this->simulationError = collect($exception->errors())->flatten()->first();
            $this->showDatePreview = false;
            $this->datePreview = [];
        }
    }

    public function generate(GenerateFlashProductions $generate): void
    {
        $this->simulationError = null;

        try {
            $productions = $generate->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                lines: $this->lines,
                firstDate: $this->firstDate,
                batchesPerDay: $this->batchesPerDay,
                idempotencyKey: $this->idempotencyKey,
            );
        } catch (ValidationException $exception) {
            $this->simulationError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->generatedPublicIds = $productions
            ->map(fn ($production): string => (string) $production->public_id)
            ->values()
            ->all();
        $this->idempotencyKey = (string) Str::uuid();
        $this->showDatePreview = false;
        $this->dispatch('flash-productions-generated');
    }

    public function render(
        FlashProductionSimulator $simulator,
        ProductionBenchAccess $access,
    ): View {
        $workspace = $this->workspace();
        $simulation = null;

        if ($this->hasEnteredLine()) {
            try {
                $simulation = $simulator->simulate($workspace, $this->lines);
                $this->simulationError = null;
            } catch (ValidationException $exception) {
                $this->simulationError = collect($exception->errors())->flatten()->first();
            }
        }

        return view('livewire.production-bench.production.flash-planner', [
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'recipes' => Recipe::query()
                ->where('workspace_id', $workspace->id)
                ->whereHas('publishedVersions')
                ->orderBy('name')
                ->get(),
            'presets' => ProductionBatchPreset::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with('recipe')
                ->orderBy('name')
                ->get(),
            'taskSets' => ProductionTaskSet::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'simulation' => $simulation,
        ]);
    }

    /** @return array<string, string> */
    private function blankLine(): array
    {
        return [
            'recipe_id' => '',
            'preset_id' => '',
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
            return;
        }

        $preset = ProductionBatchPreset::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('recipe_id', $recipeId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
        $recipe = Recipe::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace()->id)
            ->with('defaultProductionTaskSet')
            ->find($recipeId);

        if ($preset instanceof ProductionBatchPreset) {
            $this->lines[$index]['preset_id'] = (string) $preset->id;
            $this->lines[$index]['basis_input_value'] = $this->displayDecimal((string) $preset->basis_input_value);
            $this->lines[$index]['basis_input_unit'] = $preset->basis_input_unit->value;
            $this->lines[$index]['expected_units_per_batch'] = (string) $preset->expected_units;
        }

        if ($recipe instanceof Recipe && $recipe->defaultProductionTaskSet?->is_active) {
            $this->lines[$index]['task_set_id'] = (string) $recipe->defaultProductionTaskSet->id;
        }
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

        $this->lines[$index]['recipe_id'] = (string) $preset->recipe_id;
        $this->lines[$index]['basis_input_value'] = $this->displayDecimal((string) $preset->basis_input_value);
        $this->lines[$index]['basis_input_unit'] = $preset->basis_input_unit->value;
        $this->lines[$index]['expected_units_per_batch'] = (string) $preset->expected_units;
    }

    private function hasEnteredLine(): bool
    {
        return collect($this->lines)->contains(fn (array $line): bool => collect($line)->contains(fn (mixed $value): bool => filled($value)));
    }

    private function positiveWhole(string $value): int
    {
        if (preg_match('/^[1-9]\d*$/', trim($value)) !== 1) {
            throw ValidationException::withMessages([
                'batchesPerDay' => 'Enter a positive number of batches per day.',
            ]);
        }

        return (int) $value;
    }

    private function displayDecimal(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
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
