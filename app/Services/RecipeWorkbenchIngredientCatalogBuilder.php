<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\User;
use App\Models\UserIngredientPrice;

class RecipeWorkbenchIngredientCatalogBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(?User $user, ?ProductFamily $productFamily = null): array
    {
        $isCosmetic = $productFamily?->slug === 'cosmetic'
            || $productFamily?->calculation_basis === 'total_formula';
        $defaultPricesByIngredient = $user instanceof User
            ? UserIngredientPrice::query()
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('ingredient_id')
            : collect();

        return Ingredient::query()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->where('is_active', true)
            ->accessibleTo($user)
            ->whereIn('category', [
                IngredientCategory::CarrierOil->value,
                IngredientCategory::EssentialOil->value,
                IngredientCategory::FragranceOil->value,
                IngredientCategory::BotanicalExtract->value,
                IngredientCategory::Co2Extract->value,
                IngredientCategory::Clay->value,
                IngredientCategory::Glycol->value,
                IngredientCategory::Colorant->value,
                IngredientCategory::Preservative->value,
                IngredientCategory::Additive->value,
                IngredientCategory::Liquid->value,
            ])
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $isCosmetic || $ingredient->availableWorkbenchPhases() !== [])
            ->map(function (Ingredient $ingredient) use ($defaultPricesByIngredient): array {
                $category = $ingredient->category;
                $sapProfile = $ingredient->sapProfile;
                $availablePhases = $ingredient->availableWorkbenchPhases();
                $defaultPrice = $defaultPricesByIngredient->get($ingredient->id);

                return [
                    'id' => $ingredient->id,
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->display_name,
                    'inci_name' => $ingredient->inci_name,
                    'image_url' => $ingredient->pickerImageUrl(),
                    'category' => $category?->value,
                    'category_label' => $category?->getLabel(),
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
                    'default_price_per_kg' => $defaultPrice?->price_per_kg === null ? null : (float) $defaultPrice->price_per_kg,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
