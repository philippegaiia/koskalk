<?php

namespace App\Services\Production;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\ProductionBasisKind;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductionRequirementBuilder
{
    private const int GuardScale = 18;

    private const int StorageScale = 9;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        RecipeVersion $version,
        ProductionBasisKind $basisKind,
        string $basisQuantityGrams,
        int $expectedUnits,
    ): Collection {
        if (bccomp($basisQuantityGrams, '0', self::GuardScale) <= 0 || $expectedUnits < 1) {
            throw ValidationException::withMessages([
                'production' => 'Production quantities must be greater than zero.',
            ]);
        }

        $version->load([
            'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
            'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
            'phases.items.ingredient' => fn ($query) => $query->withoutGlobalScopes(),
            'packagingItems' => fn ($query) => $query->orderBy('position'),
            'packagingItems.packagingItem' => fn ($query) => $query->withoutGlobalScopes(),
        ]);

        $recipe = Recipe::withoutGlobalScopes()
            ->with('productFamily')
            ->find($version->recipe_id);

        if (! $recipe instanceof Recipe) {
            throw ValidationException::withMessages([
                'recipe' => 'The published formula recipe could not be found.',
            ]);
        }

        $expectedBasisKind = $recipe->productFamily?->calculation_basis === 'total_formula'
            ? ProductionBasisKind::TotalFormulaMass
            : ProductionBasisKind::OilMass;

        if ($basisKind !== $expectedBasisKind) {
            throw ValidationException::withMessages([
                'basis_kind' => 'The production basis does not match the published formula.',
            ]);
        }

        $requirements = collect();
        $sortOrder = 1;

        foreach ($version->phases as $phase) {
            foreach ($phase->items as $item) {
                $ingredient = $item->ingredient;

                if (! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        'recipe' => 'Every published formula ingredient must still exist.',
                    ]);
                }

                $this->assertMaterialWorkspace($ingredient, (int) $version->workspace_id, 'ingredient');

                $percentage = $this->decimal((string) $item->percentage);
                $requiredMass = $this->roundStorage(
                    bcdiv(
                        bcmul($basisQuantityGrams, $percentage, self::GuardScale),
                        '100',
                        self::GuardScale,
                    ),
                );

                if (bccomp($requiredMass, '0', self::StorageScale) <= 0) {
                    continue;
                }

                $requirements->push([
                    'ingredient_id' => $ingredient->id,
                    'packaging_item_id' => null,
                    'recipe_item_id' => $item->id,
                    'recipe_version_packaging_item_id' => null,
                    'kind' => 'ingredient',
                    'required_mass_grams' => $requiredMass,
                    'required_units' => null,
                    'subject_name_snapshot' => $ingredient->display_name,
                    'phase_key_snapshot' => $phase->slug,
                    'phase_name_snapshot' => $phase->name,
                    'percentage_snapshot' => $this->roundStorage($percentage),
                    'components_per_unit_snapshot' => null,
                    'unit_snapshot' => 'g',
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        foreach ($version->packagingItems as $packagingPlan) {
            $packagingItem = $packagingPlan->packagingItem;

            if (! $packagingItem instanceof PackagingItem) {
                continue;
            }

            $this->assertMaterialWorkspace($packagingItem, (int) $version->workspace_id, 'packaging');
            $componentsPerUnit = $this->decimal((string) $packagingPlan->components_per_unit);
            $requiredUnits = $this->ceilPositive(
                bcmul((string) $expectedUnits, $componentsPerUnit, self::GuardScale),
            );

            if ($requiredUnits < 1) {
                continue;
            }

            $requirements->push([
                'ingredient_id' => null,
                'packaging_item_id' => $packagingItem->id,
                'recipe_item_id' => null,
                'recipe_version_packaging_item_id' => $packagingPlan->id,
                'kind' => 'packaging',
                'required_mass_grams' => null,
                'required_units' => $requiredUnits,
                'subject_name_snapshot' => $packagingPlan->name !== ''
                    ? $packagingPlan->name
                    : $packagingItem->name,
                'phase_key_snapshot' => null,
                'phase_name_snapshot' => null,
                'percentage_snapshot' => null,
                'components_per_unit_snapshot' => $this->roundStorage($componentsPerUnit),
                'unit_snapshot' => 'unit',
                'sort_order' => $sortOrder++,
            ]);
        }

        return $requirements;
    }

    private function assertMaterialWorkspace(
        Ingredient|PackagingItem $material,
        int $workspaceId,
        string $field,
    ): void {
        if ($material->workspace_id !== null && (int) $material->workspace_id !== $workspaceId) {
            throw ValidationException::withMessages([
                $field => 'Every production material must belong to the production workspace.',
            ]);
        }
    }

    private function decimal(string $value): string
    {
        $normalized = trim($value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'recipe' => 'Published formula quantities must be valid decimal values.',
            ]);
        }

        return $normalized;
    }

    private function roundStorage(string $value): string
    {
        $increment = '0.'.str_repeat('0', self::StorageScale).'5';
        $adjusted = bcadd($value, $increment, self::StorageScale + 1);

        return bcadd($adjusted, '0', self::StorageScale);
    }

    private function ceilPositive(string $value): int
    {
        $whole = bcdiv($value, '1', 0);

        if (bccomp($value, $whole, self::GuardScale) > 0) {
            $whole = bcadd($whole, '1', 0);
        }

        return (int) $whole;
    }
}
