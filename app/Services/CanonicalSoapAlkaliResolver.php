<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Resolves the two Koskalk-owned canonical soap alkali materials.
 *
 * NaOH (catalog key CH1) and KOH (catalog key CH3) are the only calculated
 * alkali ingredient identities. Resolution never depends on category order,
 * subcategory order, owner priority, record ID, or the last accessible row,
 * and never falls back to a workspace duplicate.
 */
final class CanonicalSoapAlkaliResolver
{
    private const array CATALOG_KEY_BY_LYE_TYPE = [
        'naoh' => 'CH1',
        'koh' => 'CH3',
    ];

    public function resolve(string $lyeType): Ingredient
    {
        return $this->resolveMany([$lyeType])->get($lyeType);
    }

    /**
     * @param  list<string>  $lyeTypes
     * @return Collection<string, Ingredient>
     */
    public function resolveMany(array $lyeTypes): Collection
    {
        $catalogKeys = collect($lyeTypes)
            ->mapWithKeys(fn (string $lyeType): array => [
                $lyeType => $this->catalogKeyFor($lyeType),
            ]);

        $ingredients = Ingredient::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id')
            ->whereIn('catalog_key', $catalogKeys->values()->all())
            ->get()
            ->keyBy('catalog_key');

        return $catalogKeys->map(function (string $catalogKey) use ($ingredients): Ingredient {
            $ingredient = $ingredients->get($catalogKey);

            if (! $ingredient instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'recipe' => __('ingredients.alkalis.validation.canonical_missing', [
                        'key' => $catalogKey,
                    ]),
                ]);
            }

            return $ingredient;
        });
    }

    private function catalogKeyFor(string $lyeType): string
    {
        $catalogKey = self::CATALOG_KEY_BY_LYE_TYPE[$lyeType] ?? null;

        if ($catalogKey === null) {
            throw new InvalidArgumentException("Unsupported soap lye type [{$lyeType}].");
        }

        return $catalogKey;
    }
}
