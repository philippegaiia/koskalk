<?php

namespace App\Actions\Production;

use App\MassUnit;
use App\Models\ProductionRun;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionBasisKind;
use App\ProductionRunSource;
use App\ProductionRunStatus;
use App\Services\MassConverter;
use App\Services\Production\ProductionFormulaSnapshotBuilder;
use App\Services\Production\ProductionRequirementBuilder;
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
        private readonly ProductionRequirementBuilder $requirementBuilder,
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
                'status' => 'Only draft or planned productions can be created in this workflow.',
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
                        'idempotency_key' => 'This submission key is already used for another production.',
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
                    'recipe' => 'The recipe must belong to the active workspace.',
                ]);
            }

            if ($lockedRecipe->archived_at !== null) {
                throw ValidationException::withMessages([
                    'recipe' => 'Archived products cannot be planned.',
                ]);
            }

            if ($taskSet instanceof ProductionTaskSet
                && (int) $taskSet->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'production_task_set' => 'Choose a task set from the production workspace.',
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
                    'production_task_set' => 'Choose an active task set from this workspace.',
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
                    'planned_for' => 'The production date must be a working day.',
                ]);
            }

            if ($lockedTaskSet instanceof ProductionTaskSet) {
                $isApplicable = DB::table('production_task_set_recipe')
                    ->where('production_task_set_id', $lockedTaskSet->id)
                    ->where('recipe_id', $lockedRecipe->id)
                    ->exists();

                if (! $isApplicable) {
                    throw ValidationException::withMessages([
                        'production_task_set' => 'Choose a task set applicable to this product.',
                    ]);
                }
            }

            $publishedVersion = $this->publishedVersionsByRecipe[$lockedRecipe->id] ??= $this->publishedVersion($lockedRecipe, $lockedWorkspace);

            if (! $publishedVersion instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    'recipe' => 'Choose a recipe with a published formula.',
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
            $planningBatchNumber = $this->numbers->allocatePlanningReference($lockedWorkspace);

            $production = ProductionRun::query()->create([
                'workspace_id' => $lockedWorkspace->id,
                'recipe_id' => $lockedRecipe->id,
                'recipe_version_id' => $publishedVersion->id,
                'recipe_name_snapshot' => $lockedRecipe->name,
                'source_formula_version_number' => $publishedVersion->version_number,
                'formula_context_snapshot' => $formulaSnapshot['context'],
                'formula_snapshot_completed_at' => now(),
                'production_task_set_id' => $lockedTaskSet?->id,
                'status' => $status,
                'source' => $source,
                'planned_for' => $plannedFor,
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
                'basis_input_unit' => 'Choose a supported mass unit.',
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
                'basis_input_value' => 'The production basis must be greater than zero.',
            ]);
        }

        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid submission key is required.',
            ]);
        }

        if ($plannedFor !== null && ! $this->isValidDate($plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must use YYYY-MM-DD format.',
            ]);
        }
    }

    private function normalizeExpectedUnits(int|string|float $expectedUnits): int
    {
        $normalized = trim((string) $expectedUnits);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'expected_units' => 'Expected units must be a positive whole number.',
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
