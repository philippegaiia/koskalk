<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;

class RecipeCompositionIngredientIdentityResolver
{
    public function __construct(private readonly CanonicalSoapAlkaliResolver $alkaliResolver) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function withAvailableIdentities(array $snapshot): array
    {
        $selected = data_get($snapshot, 'calculation.lye.selected', []);
        $types = [];

        if ((float) ($selected['naoh_weight'] ?? 0) > 0) {
            $types[] = 'naoh';
        }

        if ((float) ($selected['koh_to_weigh'] ?? 0) > 0) {
            $types[] = 'koh';
        }

        $identities = is_array(data_get($snapshot, 'calculation.lye.identities'))
            ? data_get($snapshot, 'calculation.lye.identities')
            : [];

        foreach ($this->alkaliResolver->resolveAvailableMany($types) as $type => $ingredient) {
            $existing = is_array($identities[$type] ?? null) ? $identities[$type] : [];
            $identities[$type] = array_replace($this->identity($ingredient), $existing);
        }

        if (! is_array($identities['water'] ?? null) || $identities['water'] === []) {
            $water = $this->plainWaterIngredient();

            if ($water instanceof Ingredient) {
                $identities['water'] = $this->identity($water);
            }
        }

        data_set($snapshot, 'calculation.lye.identities', $identities);

        return $snapshot;
    }

    /** @return array{ingredient_id: int, name: string, inci_name: string|null} */
    private function identity(Ingredient $ingredient): array
    {
        return [
            'ingredient_id' => (int) $ingredient->id,
            'name' => (string) ($ingredient->localizedDisplayName() ?? ''),
            'inci_name' => $ingredient->displayInciName(),
        ];
    }

    private function plainWaterIngredient(): ?Ingredient
    {
        return Ingredient::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->where('category', IngredientCategory::WaterSolventsCarriers->value)
            ->where('subcategory', IngredientSubcategory::Water->value)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id')
            ->orderBy('id')
            ->first();
    }
}
