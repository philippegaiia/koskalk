<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;

class LyeLiquidIngredientValidator
{
    public const MAX_ROWS = 4;

    /**
     * @param  array<int, mixed>  $rows
     */
    public function assertMaximumRows(array $rows, string $field = 'phase_items.lye_water'): void
    {
        if (count($rows) <= self::MAX_ROWS) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('workbench.validation.lye_liquid_max_rows', [
                'max' => self::MAX_ROWS,
            ]),
        ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function validate(array $rows, ?User $user = null, string $field = 'phase_items.lye_water'): array
    {
        $normalizedRows = collect($this->normalizeRows($rows, $field));
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
            ->map(function (array $row) use ($ingredients, $field): array {
                if ($row['percentage'] === null || bccomp($row['percentage'], '0', 12) < 0) {
                    return $row;
                }

                $ingredient = $ingredients->get($row['ingredient_id']);

                if (! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        $field => __('workbench.validation.lye_liquid_ingredient'),
                    ]);
                }

                if ($ingredient->category === IngredientCategory::SoapmakingAlkalis) {
                    throw ValidationException::withMessages([
                        $field => __('workbench.validation.lye_liquid_alkali'),
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
    public function normalizeRows(array $rows, string $field = 'phase_items.lye_water'): array
    {
        $this->assertMaximumRows($rows, $field);

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
