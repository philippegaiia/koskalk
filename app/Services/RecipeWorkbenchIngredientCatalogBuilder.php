<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\User;

class RecipeWorkbenchIngredientCatalogBuilder
{
    public function __construct(
        private readonly IngredientAliasLocaleService $ingredientAliasLocaleService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(?User $user, ?ProductFamily $productFamily = null): array
    {
        $translationLocales = Ingredient::translationLocaleCandidates();
        $isCosmetic = $productFamily?->slug === 'cosmetic'
            || $productFamily?->calculation_basis === 'total_formula';
        $defaultPricesByIngredient = $user instanceof User
            ? CurrentMaterialPrice::query()
                ->where('workspace_id', $user->company()?->id)
                ->whereNotNull('ingredient_id')
                ->get()
                ->keyBy('ingredient_id')
            : collect();

        $relations = [
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'mediaAssetUsages.mediaAsset',
            'identifiers',
            'aliases',
        ];

        if ($translationLocales !== []) {
            $relations['translations'] = fn ($query) => $query->whereIn('locale', $translationLocales);
        }

        return Ingredient::query()
            ->with($relations)
            ->where('is_active', true)
            ->accessibleTo($user)
            ->whereIn('category', array_map(
                fn (IngredientCategory $category): string => $category->value,
                IngredientCategory::cases(),
            ))
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $isCosmetic || $ingredient->availableWorkbenchPhases() !== [])
            ->map(function (Ingredient $ingredient) use ($defaultPricesByIngredient, $translationLocales): array {
                $category = $ingredient->category;
                $subcategory = $ingredient->subcategory;
                $sapProfile = $ingredient->sapProfile;
                $availablePhases = $ingredient->availableWorkbenchPhases();
                $defaultPrice = $defaultPricesByIngredient->get($ingredient->id);

                return [
                    'id' => $ingredient->id,
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->localizedDisplayName(),
                    'is_user_owned' => $ingredient->owner_type !== null,
                    'inci_name' => $ingredient->inci_name,
                    'identifiers' => $ingredient->identifiers
                        ->map(fn ($identifier): array => [
                            'scheme' => $identifier->scheme->value,
                            'value' => $identifier->value,
                        ])
                        ->all(),
                    'aliases' => $this->ingredientAliasLocaleService
                        ->eligibleAliases($ingredient->aliases, $translationLocales)
                        ->pluck('name')
                        ->all(),
                    'image_url' => $ingredient->pickerImageUrl(),
                    'category' => $category?->value,
                    'category_label' => $category?->getLabel(),
                    'subcategory' => $subcategory?->value,
                    'subcategory_label' => $subcategory?->getLabel(),
                    'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                    'needs_compliance' => $ingredient->requiresAromaticCompliance(),
                    'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
                    'naoh_sap_value' => $sapProfile?->naoh_sap_value,
                    'fatty_acid_profile' => $ingredient->normalizedFattyAcidProfile(),
                    'available_phases' => $availablePhases,
                    'default_phase' => $ingredient->preferredWorkbenchPhase(),
                    'can_add_to_saponified_oils' => in_array('saponified_oils', $availablePhases, true),
                    'can_add_to_additives' => in_array('additives', $availablePhases, true),
                    'can_add_to_fragrance' => in_array('fragrance', $availablePhases, true),
                    'default_price_per_kg' => $defaultPrice?->price_per_canonical_unit === null
                        ? null
                        : (float) bcmul($defaultPrice->price_per_canonical_unit, '1000', 12),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
