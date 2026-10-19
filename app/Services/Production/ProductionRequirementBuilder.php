<?php

namespace App\Services\Production;

use App\Enums\ProductionBasisKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Services\MassConverter;
use App\Services\RecipeWorkbenchPhaseBlueprints;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductionRequirementBuilder
{
    private const int GuardScale = 18;

    private const int StorageScale = 9;

    public function __construct(private readonly MassConverter $massConverter) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        RecipeVersion $version,
        ProductionBasisKind $basisKind,
        string $basisQuantityGrams,
        int $expectedUnits,
        ?Recipe $recipe = null,
    ): Collection {
        if (bccomp($basisQuantityGrams, '0', self::GuardScale) <= 0 || $expectedUnits < 1) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.validation.quantities_positive'),
            ]);
        }

        $relationsLoaded = $version->relationLoaded('phases')
            && $version->relationLoaded('packagingItems')
            && $version->phases->every(fn ($phase): bool => $phase->relationLoaded('items')
                && $phase->items->every(fn ($item): bool => $item->relationLoaded('ingredient')))
            && $version->packagingItems->every(fn ($packagingPlan): bool => $packagingPlan->relationLoaded('packagingItem'));

        if (! $relationsLoaded) {
            $version->load([
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
                'phases.items.ingredient' => fn ($query) => $query->withoutGlobalScopes(),
                'packagingItems' => fn ($query) => $query->orderBy('position'),
                'packagingItems.packagingItem' => fn ($query) => $query->withoutGlobalScopes(),
            ]);
        }

        $recipe ??= Recipe::withoutGlobalScopes()
            ->with('productFamily')
            ->find($version->recipe_id);

        if (! $recipe instanceof Recipe) {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.published_recipe_missing'),
            ]);
        }

        $expectedBasisKind = $recipe->productFamily?->calculation_basis === 'total_formula'
            ? ProductionBasisKind::TotalFormulaMass
            : ProductionBasisKind::OilMass;

        if ($basisKind !== $expectedBasisKind) {
            throw ValidationException::withMessages([
                'basis_kind' => __('production_bench.production.validation.basis_mismatch'),
            ]);
        }

        $requirements = collect();
        $sortOrder = 1;

        foreach ($version->phases as $phase) {
            foreach ($phase->items as $item) {
                $ingredient = $item->ingredient;

                if (! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        'recipe' => __('production_bench.production.validation.published_ingredient_missing'),
                    ]);
                }

                $this->assertMaterialWorkspace($ingredient, (int) $version->workspace_id, 'ingredient');

                $percentage = $this->decimal((string) $item->percentage);
                // Lye liquids and liquid soap dilution liquids are a % of their
                // own liquid, not of the oils, so they scale from saved weight.
                $scalesFromSavedWeight = in_array($phase->slug, ['lye_water', 'neutralization', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true);
                $requiredMass = $this->roundStorage(match (true) {
                    $phase->slug === 'neutralization' => $this->neutralizerDose($version, $item, $basisQuantityGrams)['mass_grams'],
                    $scalesFromSavedWeight => $this->scaledSavedMass($version, (string) $item->weight, $basisQuantityGrams),
                    default => bcdiv(bcmul($basisQuantityGrams, $percentage, self::GuardScale), '100', self::GuardScale),
                });

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
                    'percentage_snapshot' => $scalesFromSavedWeight
                        ? $this->roundStorage(bcdiv(bcmul($requiredMass, '100', self::GuardScale), $basisQuantityGrams, self::GuardScale))
                        : $this->roundStorage($percentage),
                    'components_per_unit_snapshot' => null,
                    'unit_snapshot' => 'g',
                    'note_snapshot' => $item->note,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        foreach ($version->packagingItems as $packagingPlan) {
            $packagingItem = $packagingPlan->packagingItem;

            if (! $packagingItem instanceof PackagingItem) {
                throw ValidationException::withMessages([
                    'packaging' => __('production_bench.production.validation.published_packaging_missing'),
                ]);
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
                'note_snapshot' => $packagingPlan->notes,
                'sort_order' => $sortOrder++,
            ]);
        }

        return $requirements;
    }

    /**
     * A liquid soap neutralizer's dose at the production basis, from whichever
     * saved quantity is more precise: its weight (four decimals in the batch
     * unit) or its percentage of the oils (four decimals). The selected dose
     * is scaled as saved, without substituting the theoretical amount.
     *
     * @return array{mass_grams: string}
     */
    public function neutralizerDose(RecipeVersion $version, RecipeItem $item, string $basisQuantityGrams): array
    {
        $savedBasisGrams = $this->savedBasisGrams($version);
        $unit = $version->batch_unit ?: 'g';
        $lastDecimalGrams = (string) $this->massConverter->toGrams('0.00005', $unit);
        $scale = fn (string $grams): string => bcdiv(bcmul($grams, $basisQuantityGrams, self::GuardScale), $savedBasisGrams, self::GuardScale);
        $weightRounding = $scale($lastDecimalGrams);
        $percentageRounding = bcdiv(bcmul('0.00005', $basisQuantityGrams, self::GuardScale), '100', self::GuardScale);
        $weight = $item->weight === null ? '0' : $this->decimal((string) $item->weight);
        $useWeight = bccomp($weight, '0', self::GuardScale) > 0 && bccomp($weightRounding, $percentageRounding, self::GuardScale) < 0;

        return [
            'mass_grams' => $useWeight
                ? $this->scaledSavedMass($version, $weight, $basisQuantityGrams)
                : bcdiv(bcmul($basisQuantityGrams, $this->decimal((string) $item->percentage), self::GuardScale), '100', self::GuardScale),
        ];
    }

    /**
     * The basis mass the formula's row weights were saved for, in grams.
     */
    public function savedBasisGrams(RecipeVersion $version): string
    {
        $referenceBasisGrams = (string) ($version->batch_mass_grams
            ?? $this->massConverter->toGrams((string) $version->batch_size, $version->batch_unit ?: 'g'));

        if (bccomp($referenceBasisGrams, '0', self::GuardScale) <= 0) {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.published_formula_basis_positive'),
            ]);
        }

        return $referenceBasisGrams;
    }

    /**
     * A saved row weight scaled from the formula's saved basis to the
     * production basis, unrounded, in grams.
     */
    private function scaledSavedMass(RecipeVersion $version, string $savedWeight, string $basisQuantityGrams): string
    {
        $referenceBasisGrams = $this->savedBasisGrams($version);
        $savedWeightGrams = (string) $this->massConverter->toGrams($savedWeight, $version->batch_unit ?: 'g');

        return bcdiv(
            bcmul($savedWeightGrams, $basisQuantityGrams, self::GuardScale),
            $referenceBasisGrams,
            self::GuardScale,
        );
    }

    private function assertMaterialWorkspace(
        Ingredient|PackagingItem $material,
        int $workspaceId,
        string $field,
    ): void {
        if ($material->workspace_id !== null && (int) $material->workspace_id !== $workspaceId) {
            throw ValidationException::withMessages([
                $field => __('production_bench.production.validation.material_workspace_invalid'),
            ]);
        }
    }

    private function decimal(string $value): string
    {
        $normalized = trim($value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.published_quantity_decimal'),
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
