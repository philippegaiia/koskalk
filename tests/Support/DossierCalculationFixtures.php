<?php

namespace Tests\Support;

use App\Data\DossierExposureParameters;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Services\RecipeWorkbenchService;

/**
 * Inputs for the DIP engine tests. The expected values that go with them are in the phase 3 plan, computed by hand.
 */
final class DossierCalculationFixtures
{
    /**
     * DIP.GA.007's stated inputs: A = 1 g × 10 uses/day, R = 0.01, bw = 60 kg.
     */
    public static function pilotExposure(): DossierExposureParameters
    {
        return new DossierExposureParameters('1', '10', '0.01', '60');
    }

    /**
     * 1000 g olive oil (KOH SAP 0.188), NaOH, superfat 5 %, water 38 % of oils, saved through the workbench in a
     * workspace that has the DIP module. Extra rows come through $phaseItems (e.g. 'fragrance', 'additives').
     *
     * @param  array<string, list<array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}>>  $phaseItems
     * @param  array<string, mixed>  $payloadOverrides
     * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, workingCopy: RecipeVersion, olive: Ingredient}
     */
    public static function oliveSoap(array $phaseItems = [], array $payloadOverrides = [], ?Ingredient $oil = null, int|float $oilWeight = 1000): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        WorkspaceDossierGrant::factory()->for($workspace)->create();
        $family = ProductFamily::query()->where('slug', 'soap')->first()
            ?? ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap', 'calculation_basis' => 'oil_weight']);
        $olive = $oil ?? self::oil('Olive Oil', 'OLEA EUROPAEA FRUIT OIL', 'SODIUM OLIVATE', 'POTASSIUM OLIVATE', 0.188);

        $payload = [
            'name' => 'DIP engine soap',
            'product_type_id' => testProductTypeIdForFamily('soap'),
            'oil_unit' => 'g',
            'oil_weight' => $oilWeight,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'eu',
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 100,
            'dual_lye_koh_percentage' => 40,
            'water_mode' => 'percent_of_oils',
            'water_value' => 38,
            'superfat' => 5,
            'ifra_product_category_id' => null,
            'phase_items' => [
                'saponified_oils' => [self::row($olive, 100, $oilWeight)],
                'additives' => [],
                'fragrance' => [],
                ...$phaseItems,
            ],
            ...$payloadOverrides,
        ];

        $service = app(RecipeWorkbenchService::class);
        $draft = $service->save($owner, $family, $payload);
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
        $service->saveAsNewVersion($owner, $family, $payload, $recipe);
        $versions = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->get();

        return [
            'owner' => $owner,
            'workspace' => $workspace,
            'recipe' => $recipe,
            'version' => $versions->firstWhere('is_current', false),
            'workingCopy' => $versions->firstWhere('is_current', true),
            'olive' => $olive,
        ];
    }

    /**
     * A trusted saponifiable lipid with its SAP profile; a vegetable oil unless another subcategory is given.
     */
    public static function oil(string $name, string $inci, string $sodiumSalt, string $potassiumSalt, float $kohSap, ?IngredientSubcategory $subcategory = IngredientSubcategory::VegetableOils): Ingredient
    {
        $oil = self::ingredient($name, $inci, IngredientCategory::Lipids, [
            'subcategory' => $subcategory,
            'soap_inci_naoh_name' => $sodiumSalt,
            'soap_inci_koh_name' => $potassiumSalt,
            'is_soap_saponification_trusted' => true,
        ]);
        IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => $kohSap]);

        return $oil;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function ingredient(string $name, string $inci, IngredientCategory $category, array $attributes = []): Ingredient
    {
        return Ingredient::factory()->create([
            'category' => $category,
            'display_name' => $name,
            'inci_name' => $inci,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @return array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}
     */
    public static function row(Ingredient $ingredient, int|float $percentage, int|float $weight): array
    {
        return ['ingredient_id' => $ingredient->id, 'percentage' => $percentage, 'weight' => $weight, 'note' => null];
    }
}
