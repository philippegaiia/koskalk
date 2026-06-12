<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionSnapshotService
{
    public function __construct(
        private readonly RecipeVersionCostPreviewBuilder $costPreviewBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Recipe $recipe, RecipeVersion $version, User $user, array $input): array
    {
        $batchBasisValue = $this->positiveFloat($input['batch_basis'] ?? null)
            ?? $this->positiveFloat($version->batch_size)
            ?? 0.0;
        $unitsProduced = $this->positiveInt($input['units_produced'] ?? null);

        return [
            ...$this->costPreviewBuilder->ensureCostingAndBuild(
                recipe: $recipe,
                version: $version,
                user: $user,
                batchBasisValue: $batchBasisValue,
                unitsProduced: $unitsProduced,
            ),
            'batch_basis_label' => $this->batchBasisLabel($recipe),
            'batch_basis_value' => $batchBasisValue,
            'batch_basis_unit' => $version->batch_unit ?: 'g',
            'units_produced' => $unitsProduced,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(Recipe $recipe, RecipeVersion $version, User $user, array $input): ProductionBatch
    {
        $ingredientLotNumbers = $input['ingredient_lot_numbers'] ?? [];

        return DB::transaction(function () use ($ingredientLotNumbers, $input, $recipe, $user, $version): ProductionBatch {
            $preview = $this->preview($recipe, $version, $user, $input);
            $this->ensurePreviewIsPriced($preview);

            $recipe->loadMissing('productFamily');

            $batch = ProductionBatch::query()->create([
                'user_id' => $user->id,
                'recipe_id' => $recipe->id,
                'recipe_version_id' => $version->id,
                'recipe_name' => $recipe->name,
                'recipe_version_number' => $version->version_number,
                'product_family_slug' => $recipe->productFamily?->slug ?? '',
                'production_batch_number' => $this->nullableString($input['production_batch_number'] ?? null),
                'manufacture_date' => (string) ($input['manufacture_date'] ?? ''),
                'batch_basis_label' => $preview['batch_basis_label'],
                'batch_basis_value' => $preview['batch_basis_value'],
                'batch_basis_unit' => $preview['batch_basis_unit'],
                'units_produced' => $preview['units_produced'] ?? 0,
                'currency' => $preview['currency'],
                'ingredient_total' => $preview['ingredient_total'],
                'packaging_total' => $preview['packaging_total'],
                'total_cost' => $preview['total_cost'],
                'cost_per_unit' => $preview['cost_per_unit'] ?? 0,
                'production_notes' => $this->nullableString($input['production_notes'] ?? null),
            ]);

            foreach ($preview['ingredient_rows'] as $row) {
                $lotKey = (string) $row['lot_key'];

                $batch->ingredients()->create([
                    'ingredient_id' => $row['ingredient_id'],
                    'raw_material_lot_id' => null,
                    'phase_key' => $row['phase_key'],
                    'phase_name' => $row['phase_name'],
                    'position' => $row['position'],
                    'ingredient_name' => $row['ingredient_name'],
                    'percentage' => $row['percentage'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'price_per_kg' => $row['price_per_kg'],
                    'line_cost' => $row['line_cost'],
                    'ingredient_lot_number' => is_array($ingredientLotNumbers)
                        ? $this->nullableString($ingredientLotNumbers[$lotKey] ?? null)
                        : null,
                ]);
            }

            foreach ($preview['packaging_rows'] as $row) {
                $batch->packagingItems()->create([
                    'user_packaging_item_id' => $row['user_packaging_item_id'],
                    'position' => $row['position'],
                    'name' => $row['name'],
                    'components_per_unit' => $row['components_per_unit'],
                    'unit_cost' => $row['unit_cost'] ?? 0,
                    'cost_per_finished_unit' => $row['cost_per_finished_unit'],
                    'line_cost' => $row['line_cost'],
                ]);
            }

            return $batch->fresh(['ingredients', 'packagingItems']) ?? $batch->load(['ingredients', 'packagingItems']);
        });
    }

    private function batchBasisLabel(Recipe $recipe): string
    {
        $recipe->loadMissing('productFamily');

        return $recipe->productFamily?->calculation_basis === 'total_formula'
            ? 'Total batch quantity'
            : 'Oil quantity';
    }

    /**
     * @param  array<string, mixed>  $preview
     *
     * @throws ValidationException
     */
    private function ensurePreviewIsPriced(array $preview): void
    {
        if (! (bool) ($preview['has_unpriced_rows'] ?? false)) {
            return;
        }

        throw ValidationException::withMessages([
            'costing' => 'Production snapshots require prices for every ingredient and packaging row before recording.',
        ]);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
