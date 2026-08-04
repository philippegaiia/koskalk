<?php

namespace App\Actions\Production;

use App\MassUnit;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionBatchPreset
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        Recipe $recipe,
        string $name,
        string|int|float $basisInputValue,
        MassUnit|string $basisInputUnit,
        int|string|float $expectedUnits,
        bool $isDefault = false,
        bool $isActive = true,
        ?ProductionBatchPreset $preset = null,
    ): ProductionBatchPreset {
        $this->access->assertWritable($actor, $workspace);

        $name = trim($name);
        $this->validateName($name);
        $expectedUnits = $this->normalizeExpectedUnits($expectedUnits);
        $massUnit = $this->massUnit($basisInputUnit);
        $basisInputValue = $this->normalizeMassValue($basisInputValue);
        $basisQuantityGrams = $this->massConverter->toGrams($basisInputValue, $massUnit);

        return DB::transaction(function () use (
            $actor,
            $basisInputValue,
            $basisQuantityGrams,
            $expectedUnits,
            $isActive,
            $isDefault,
            $massUnit,
            $name,
            $preset,
            $recipe,
            $workspace,
        ): ProductionBatchPreset {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedRecipe = Recipe::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($recipe->id);

            if (! $lockedRecipe instanceof Recipe || (int) $lockedRecipe->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'recipe' => 'The recipe must belong to the production workspace.',
                ]);
            }

            $currentPreset = null;

            if ($preset instanceof ProductionBatchPreset) {
                $currentPreset = ProductionBatchPreset::query()
                    ->lockForUpdate()
                    ->find($preset->id);

                if (
                    ! $currentPreset instanceof ProductionBatchPreset
                    || (int) $currentPreset->workspace_id !== (int) $lockedWorkspace->id
                    || (int) $currentPreset->recipe_id !== (int) $lockedRecipe->id
                ) {
                    throw ValidationException::withMessages([
                        'preset' => 'The batch preset does not belong to this recipe and workspace.',
                    ]);
                }
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'recipe_id' => $lockedRecipe->id,
                'name' => $name,
                'basis_quantity_grams' => $basisQuantityGrams,
                'basis_input_value' => $basisInputValue,
                'basis_input_unit' => $massUnit,
                'expected_units' => $expectedUnits,
                'is_default' => $isDefault && $isActive,
                'is_active' => $isActive,
            ];

            if ($values['is_default']) {
                ProductionBatchPreset::query()
                    ->where('recipe_id', $lockedRecipe->id)
                    ->when(
                        $currentPreset instanceof ProductionBatchPreset,
                        fn ($query) => $query->whereKeyNot($currentPreset->id),
                    )
                    ->update(['is_default' => false]);
            }

            if ($currentPreset instanceof ProductionBatchPreset) {
                $currentPreset->update($values);

                return $currentPreset->fresh();
            }

            return ProductionBatchPreset::query()->create($values);
        }, attempts: 5);
    }

    private function validateName(string $name): void
    {
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages([
                'name' => 'Preset name must be between 1 and 120 characters.',
            ]);
        }
    }

    private function normalizeMassValue(string|int|float $value): string
    {
        $normalized = is_float($value)
            ? rtrim(rtrim(sprintf('%.18F', $value), '0'), '.')
            : trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1 || bccomp($normalized, '0', 18) <= 0) {
            throw ValidationException::withMessages([
                'basis_input_value' => 'Preset mass must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeExpectedUnits(int|string|float $value): int
    {
        $normalized = trim((string) $value);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'expected_units' => 'Expected units must be a positive whole number.',
            ]);
        }

        return (int) $normalized;
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
}
