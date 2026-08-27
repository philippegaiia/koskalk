<?php

namespace App\Actions\Production;

use App\Enums\MassUnit;
use App\Enums\ProductionBasisKind;
use App\Enums\ProductionRunSource;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\Production\ProductionCalculatedRequirementBuilder;
use App\Services\Production\ProductionFormulaSnapshotBuilder;
use App\Services\Production\ProductionReadyDateService;
use App\Services\Production\ProductionRequirementBuilder;
use App\Services\Production\ProductionRequirementMaterialCodeSnapshotter;
use App\Services\Production\ProductionRunNumberService;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductionDraft
{
    /** @var array<int, ?RecipeVersion> */
    private array $publishedVersionsByRecipe = [];

    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly ProductionFormulaSnapshotBuilder $formulaSnapshotBuilder,
        private readonly ProductionCalculatedRequirementBuilder $calculatedRequirementBuilder,
        private readonly ProductionRequirementBuilder $requirementBuilder,
        private readonly ProductionRequirementMaterialCodeSnapshotter $materialCodeSnapshots,
        private readonly ProductionReadyDateService $readyDates,
        private readonly ProductionRunNumberService $numbers,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        Recipe $recipe,
        string $basisInputValue,
        MassUnit|string $basisInputUnit,
        int|string|float $expectedUnits,
        string $idempotencyKey,
        ?string $plannedFor = null,
        ?string $notes = null,
        ProductionRunSource $source = ProductionRunSource::Direct,
        ProductionRunStatus $status = ProductionRunStatus::Draft,
        ?ProductionTaskSet $taskSet = null,
    ): ProductionRun {
        $this->access->assertWritable($actor, $workspace);

        if (! in_array($status, [ProductionRunStatus::Draft, ProductionRunStatus::Scheduled], true)) {
            throw ValidationException::withMessages([
                'status' => __('production_bench.production.validation.create_status_invalid'),
            ]);
        }

        if ($status === ProductionRunStatus::Scheduled && $plannedFor === null) {
            throw ValidationException::withMessages([
                'planned_for' => __('production_bench.production.validation.planned_date_required'),
            ]);
        }

        $expectedUnits = $this->normalizeExpectedUnits($expectedUnits);
        $this->validateInput($basisInputValue, $expectedUnits, $idempotencyKey, $plannedFor);

        $massUnit = $this->massUnit($basisInputUnit);
        $basisQuantityGrams = $this->massConverter->toGrams($basisInputValue, $massUnit);

        return DB::transaction(function () use (
            $actor,
            $basisInputValue,
            $basisInputUnit,
            $basisQuantityGrams,
            $expectedUnits,
            $idempotencyKey,
            $notes,
            $plannedFor,
            $recipe,
            $source,
            $status,
            $taskSet,
            $workspace,
        ): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $existing = ProductionRun::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('requirements')
                ->first();

            if ($existing instanceof ProductionRun) {
                if ($existing->recipe_id !== $recipe->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('production_bench.production.validation.create_idempotency_conflict'),
                    ]);
                }

                return $existing;
            }

            $lockedRecipe = Recipe::withoutGlobalScopes()
                ->with('productFamily')
                ->lockForUpdate()
                ->findOrFail($recipe->id);

            if ((int) $lockedRecipe->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'recipe' => __('production_bench.production.validation.recipe_workspace_invalid'),
                ]);
            }

            if ($lockedRecipe->archived_at !== null) {
                throw ValidationException::withMessages([
                    'recipe' => __('production_bench.production.validation.recipe_archived'),
                ]);
            }

            if ($taskSet instanceof ProductionTaskSet
                && (int) $taskSet->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'production_task_set' => __('production_bench.production.validation.task_set_workspace_invalid'),
                ]);
            }

            $lockedTaskSet = $taskSet instanceof ProductionTaskSet
                ? ProductionTaskSet::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find($taskSet->id)
                : null;

            if ($taskSet instanceof ProductionTaskSet && $lockedTaskSet === null) {
                throw ValidationException::withMessages([
                    'production_task_set' => __('production_bench.production.validation.task_set_active_workspace_required'),
                ]);
            }

            // Resolve and pin the product's active default task set while the
            // recipe is still locked, so later task generation never needs to
            // look the product up again.
            if ($lockedTaskSet === null) {
                $lockedTaskSet = $lockedRecipe->defaultProductionTaskSets()
                    ->where('production_task_sets.workspace_id', $lockedWorkspace->id)
                    ->where('production_task_sets.is_active', true)
                    ->lockForUpdate()
                    ->first();
            }

            if ($plannedFor !== null && ! $this->calendar->isWorkingDate($lockedWorkspace, $plannedFor)) {
                throw ValidationException::withMessages([
                    'planned_for' => __('production_bench.production.validation.planned_date_working_day'),
                ]);
            }

            if ($lockedTaskSet instanceof ProductionTaskSet) {
                $isApplicable = DB::table('production_task_set_recipe')
                    ->where('production_task_set_id', $lockedTaskSet->id)
                    ->where('recipe_id', $lockedRecipe->id)
                    ->exists();

                if (! $isApplicable) {
                    throw ValidationException::withMessages([
                        'production_task_set' => __('production_bench.production.validation.task_set_product_invalid'),
                    ]);
                }
            }

            $publishedVersion = $this->publishedVersionsByRecipe[$lockedRecipe->id] ??= $this->publishedVersion($lockedRecipe, $lockedWorkspace);

            if (! $publishedVersion instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    'recipe' => __('production_bench.production.validation.recipe_published_formula_required'),
                ]);
            }

            $basisKind = $this->basisKind($lockedRecipe);
            $requirements = $this->requirementBuilder->build(
                version: $publishedVersion,
                basisKind: $basisKind,
                basisQuantityGrams: $basisQuantityGrams,
                expectedUnits: $expectedUnits,
                recipe: $lockedRecipe,
            );
            $formulaSnapshot = $this->formulaSnapshotBuilder->build(
                recipe: $lockedRecipe,
                version: $publishedVersion,
                basisQuantityGrams: $basisQuantityGrams,
                requirements: $requirements,
            );
            $requirements = $requirements->concat(
                $this->calculatedRequirementBuilder->build(
                    formulaLines: $formulaSnapshot['lines'],
                    startingSortOrder: ((int) $requirements->max('sort_order')) + 1,
                ),
            )->values();
            $requirements = $this->materialCodeSnapshots->apply($lockedWorkspace, $requirements);
            $outputSnapshot = $this->readyDates->snapshot($lockedRecipe, $lockedWorkspace, $plannedFor);
            $planningBatchNumber = $this->numbers->allocatePlanningReference($lockedWorkspace);

            $production = ProductionRun::query()->create([
                'workspace_id' => $lockedWorkspace->id,
                'recipe_id' => $lockedRecipe->id,
                'recipe_version_id' => $publishedVersion->id,
                'production_output_type' => $outputSnapshot['production_output_type'],
                'output_ingredient_id' => $outputSnapshot['output_ingredient_id'],
                'output_ready_delay_days' => $outputSnapshot['output_ready_delay_days'],
                'recipe_name_snapshot' => $lockedRecipe->name,
                'source_formula_version_number' => $publishedVersion->version_number,
                'formula_context_snapshot' => $formulaSnapshot['context'],
                'formula_snapshot_completed_at' => now(),
                'production_task_set_id' => $lockedTaskSet?->id,
                'status' => $status,
                'source' => $source,
                'planned_for' => $plannedFor,
                'estimated_ready_on' => $outputSnapshot['estimated_ready_on'],
                'basis_kind' => $basisKind,
                'basis_quantity_grams' => $basisQuantityGrams,
                'basis_input_value' => $basisInputValue,
                'basis_input_unit' => $basisInputUnit instanceof MassUnit
                    ? $basisInputUnit
                    : $this->massUnit($basisInputUnit),
                'expected_units' => $expectedUnits,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'created_by_user_id' => $actor->id,
                'planning_batch_number' => $planningBatchNumber,
            ]);

            $production->requirements()->createMany($requirements->all());
            $production->formulaLines()->createMany($formulaSnapshot['lines']->all());

            return $production->load(['requirements', 'formulaLines']);
        }, attempts: 5);
    }

    private function basisKind(Recipe $recipe): ProductionBasisKind
    {
        return $recipe->productFamily?->calculation_basis === 'total_formula'
            ? ProductionBasisKind::TotalFormulaMass
            : ProductionBasisKind::OilMass;
    }

    private function publishedVersion(Recipe $recipe, Workspace $workspace): ?RecipeVersion
    {
        return RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('workspace_id', $workspace->id)
            ->where('is_current', false)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();
    }

    private function massUnit(MassUnit|string $unit): MassUnit
    {
        try {
            return $unit instanceof MassUnit ? $unit : MassUnit::fromInput($unit);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'basis_input_unit' => __('production_bench.production.validation.basis_input_unit_invalid'),
            ]);
        }
    }

    private function validateInput(
        string $basisInputValue,
        int $expectedUnits,
        string $idempotencyKey,
        ?string $plannedFor,
    ): void {
        if (
            preg_match('/^\d+(?:\.\d+)?$/', trim($basisInputValue)) !== 1
            || bccomp(trim($basisInputValue), '0', 18) <= 0
        ) {
            throw ValidationException::withMessages([
                'basis_input_value' => __('production_bench.production.validation.basis_input_positive'),
            ]);
        }

        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('production_bench.production.validation.idempotency_key_invalid'),
            ]);
        }

        if ($plannedFor !== null && ! $this->isValidDate($plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => __('production_bench.production.validation.planned_date_format'),
            ]);
        }
    }

    private function normalizeExpectedUnits(int|string|float $expectedUnits): int
    {
        $normalized = trim((string) $expectedUnits);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'expected_units' => __('production_bench.production.validation.expected_units_positive_whole'),
            ]);
        }

        return (int) $normalized;
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
