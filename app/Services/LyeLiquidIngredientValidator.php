<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;

class LyeLiquidIngredientValidator
{
    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function validate(array $rows, ?User $user = null): array
    {
        $normalizedRows = collect($this->normalizeRows($rows));
        $ingredientIds = $normalizedRows
            ->filter(fn (array $row): bool => $row['percentage'] !== null
                && bccomp($row['percentage'], '0', 12) > 0)
            ->pluck('ingredient_id')
            ->filter()
            ->unique()
            ->all();
        $ingredients = $ingredientIds === []
            ? collect()
            : Ingredient::query()
                ->where('is_active', true)
                ->accessibleTo($user)
                ->whereKey($ingredientIds)
                ->get()
                ->keyBy('id');

        return $normalizedRows
            ->map(function (array $row) use ($ingredients): array {
                if ($row['percentage'] === null || bccomp($row['percentage'], '0', 12) < 0) {
                    return $row;
                }

                $ingredient = $ingredients->get($row['ingredient_id']);

                if (! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        'phase_items.lye_water' => __('workbench.validation.lye_liquid_ingredient'),
                    ]);
                }

                if ($ingredient->category === IngredientCategory::SoapmakingAlkalis) {
                    throw ValidationException::withMessages([
                        'phase_items.lye_water' => __('workbench.validation.lye_liquid_alkali'),
                    ]);
                }

                return [...$row, 'ingredient_id' => $ingredient->id];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRows(array $rows): array
    {
        $normalizedRows = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row): ?array {
                $rawPercentage = trim((string) ($row['percentage'] ?? ''));
                $percentage = $this->decimal($row['percentage'] ?? null);

                if ($rawPercentage === '' || ($percentage !== null && bccomp($percentage, '0', 12) === 0)) {
                    return null;
                }

                return [
                    ...$row,
                    'ingredient_id' => is_numeric($row['ingredient_id'] ?? null)
                    ? (int) $row['ingredient_id']
                    : null,
                    'percentage' => $percentage,
                ];
            })
            ->filter()
            ->values();

        return $normalizedRows->all();
    }

    private function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            return rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');
        }

        return NumberLocale::normalizeDecimalString($value);
    }
}
