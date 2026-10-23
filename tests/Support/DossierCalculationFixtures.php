<?php

namespace Tests\Support;

use App\Data\DossierExposureParameters;
use App\Enums\AssessmentReviewState;
use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Allergen;
use App\Models\AssessmentPolicy;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use App\Models\ExposurePreset;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\MaterialAssessmentCoverage;
use App\Models\ProductFamily;
use App\Models\Qra2Parameter;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\ToxicologyProfile;
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

    /**
     * Calculation parameters in the shape AssessmentParameterResolver returns, with the pilot exposure, DAp 100 %,
     * oral bioavailability 50 %, MoS threshold 100, rinse-off.
     *
     * @return array<string, mixed>
     */
    public static function pilotParameters(?Qra2Parameter $qra2 = null): array
    {
        return [
            'exposure' => self::pilotExposure(),
            'policy_dap' => '100',
            'policy_oral_bioavailability' => '50',
            'mos_threshold' => '100',
            'is_rinse_off' => true,
            'qra2' => $qra2,
            'used' => [],
        ];
    }

    /**
     * An assessed material with a current toxicological profile.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $attributes
     */
    public static function substance(string $name, ?string $inci, array $profile = [], array $attributes = []): Substance
    {
        $substance = Substance::factory()->create(['name' => $name, 'inci_name' => $inci, ...$attributes]);
        ToxicologyProfile::factory()->create(['substance_id' => $substance->id, ...$profile]);

        return $substance;
    }

    /**
     * A sufficient review of a material, resting on the current profile of each reference.
     */
    public static function cover(Ingredient|Substance $material, Substance ...$references): MaterialAssessmentCoverage
    {
        $coverage = MaterialAssessmentCoverage::factory()->create([
            'coverable_type' => $material::class,
            'coverable_id' => $material->getKey(),
            'workspace_id' => $material instanceof Ingredient ? $material->workspace_id : null,
            'review_state' => AssessmentReviewState::Sufficient,
            'covers_whole_material' => true,
            'covers_constituents' => true,
        ]);

        foreach ($references as $reference) {
            $coverage->supportingSubstances()->attach($reference->id, [
                'toxicology_profile_id' => ToxicologyProfile::query()->current()->where('substance_id', $reference->id)->value('id'),
            ]);
        }

        return $coverage;
    }

    /**
     * Marks a substance's profiles as superseded, as a publisher would leave them, without the model's append-only guard.
     */
    public static function supersedeProfiles(Substance $substance): void
    {
        ToxicologyProfile::query()->where('substance_id', $substance->id)->update(['is_current' => false, 'superseded_at' => now()]);
    }

    /**
     * The olive soap with 3 % lavender oil and everything the engine needs to assess it: policy (DAp 100 %, oral
     * bioavailability 50 %, MoS 100, bw 60 kg), the toilet-soap preset for its product type, profiles and sufficient
     * reviews for every initial and final material, and a validated lavender supplier declaring 40 % limonene.
     * Extra 'fragrance' and 'additives' rows come through $phaseItems.
     *
     * @param  array<string, list<array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}>>  $phaseItems
     * @return array<string, mixed>
     */
    public static function assessedLavenderSoap(array $phaseItems = []): array
    {
        $lavender = self::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
        $soap = self::oliveSoap([
            'fragrance' => [self::row($lavender, 3, 30), ...($phaseItems['fragrance'] ?? [])],
            'additives' => $phaseItems['additives'] ?? [],
        ]);
        AssessmentPolicy::factory()->create();
        ExposurePreset::factory()->create(['product_type_id' => $soap['recipe']->product_type_id]);

        $olivate = self::substance('Sodium olivate', 'SODIUM OLIVATE', ['noael_mg_kg_bw_day' => '1000']);
        $glycerin = self::substance('Glycerin', 'GLYCERIN', ['noael_mg_kg_bw_day' => '1180', 'dermal_absorption_percent' => '7.34']);
        $aqua = self::substance('Water', 'AQUA', ['noael_mg_kg_bw_day' => null, 'noael_route' => null, 'cmax_percent' => '100']);
        $oliveOil = self::substance('Olea europaea fruit oil', 'OLEA EUROPAEA FRUIT OIL', ['noael_mg_kg_bw_day' => '2000']);
        $lavenderOil = self::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL', ['noael_mg_kg_bw_day' => '200', 'sensitisation_note' => 'Contains fragrance allergens; see QRA2.']);
        $limoneneAllergen = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
        $limonene = self::substance('Limonene', 'LIMONENE', ['noael_mg_kg_bw_day' => '150', 'nesil_ug_cm2' => '10000', 'sensitisation_note' => 'Skin sensitiser (oxidised).'], ['allergen_id' => $limoneneAllergen->id]);

        $coverages = [];

        foreach (compact('olivate', 'glycerin', 'aqua') as $name => $finalMaterial) {
            $coverages[$name] = self::cover($finalMaterial, $finalMaterial);
        }

        $coverages['olive'] = self::cover($soap['olive'], $oliveOil);
        $coverages['lavender'] = self::cover($lavender, $lavenderOil, $limonene);

        $qualification = SupplierQualification::factory()->create(['workspace_id' => $soap['workspace']->id, 'ingredient_id' => $lavender->id]);
        $declaration = DossierEvidenceItem::factory()->create([
            'supplier_qualification_id' => $qualification->id,
            'type' => EvidenceType::AllergenDeclaration,
            'constituents_complete' => true,
        ]);
        DossierEvidenceConstituent::factory()->create([
            'dossier_evidence_item_id' => $declaration->id,
            'kind' => ConstituentKind::Allergen,
            'allergen_id' => $limoneneAllergen->id,
            'max_value' => '40',
        ]);

        return [
            ...$soap,
            'lavender' => $lavender,
            'substances' => compact('olivate', 'glycerin', 'aqua', 'oliveOil', 'lavenderOil', 'limonene'),
            'limoneneAllergen' => $limoneneAllergen,
            'coverages' => $coverages,
        ];
    }
}
