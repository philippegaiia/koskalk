<?php

namespace Tests\Support;

use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\ProductionOutputType;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeWorkbenchService;
use Illuminate\Support\Str;

final class FormulaSharingFixtures
{
    /** @return array<string, mixed> */
    public static function offer(string $familySlug = 'soap', ?int $recipeLimit = 100, ?int $ingredientLimit = 100, ?array $options = null, bool $weightBoundary = false, bool $percentageBoundary = false, int $weightRows = 2, bool $multiLiquid = false, string $waterMode = 'percent_of_oils'): array
    {
        $owner = User::factory()->create();
        $source = Workspace::factory()->for($owner, 'owner')->create();
        $recipient = Workspace::factory()->create();
        $plan = Plan::factory()->hasLimit('saved_recipes', 100)->hasLimit('private_ingredients', 100)->hasLimit('formula_items_per_recipe', 100)->hasLimit('saved_formula_history', 10)->create();
        foreach ([$owner, $recipient->owner] as $subscriber) {
            UserEntitlement::factory()->for($subscriber)->create(['plan_id' => $plan->id]);
        }
        if (! RegulatoryRegime::query()->where('code', 'eu')->exists()) {
            RegulatoryRegime::factory()->create(['code' => 'eu', 'status' => 'active']);
        }
        foreach (['CH1', 'CH3'] as $catalogKey) {
            if (! Ingredient::withoutGlobalScopes()->where('catalog_key', $catalogKey)->exists()) {
                Ingredient::factory()->create(['catalog_key' => $catalogKey, 'category' => IngredientCategory::SoapmakingAlkalis]);
            }
        }
        $family = ProductFamily::factory()->create(['slug' => $familySlug, 'calculation_basis' => $familySlug === 'soap' ? 'initial_oils' : 'total_formula']);
        $type = ProductType::factory()->create();
        $type->productFamilies()->attach($family->id);
        $oil = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id, 'is_soap_saponification_trusted' => $familySlug === 'soap', 'source_data' => $familySlug === 'soap' ? ['user_authoring' => ['trusted_koh_sap_value' => '0.188000', 'trusted_fatty_acid_profile' => []]] : null]);
        if ($familySlug === 'soap') {
            $oil->sapProfile()->create(['koh_sap_value' => '0.190123']);
        }
        $liquid = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
        $payload = [
            'name' => 'Received Product', 'product_type_id' => $type->id, 'oil_weight' => '1000.125', 'oil_unit' => 'g',
            'manufacturing_mode' => $familySlug === 'soap' ? 'saponify_in_formula' : 'blend_only', 'exposure_mode' => 'rinse_off', 'regulatory_regime' => 'eu',
            'editing_mode' => 'percentage', 'lye_type' => 'dual', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40, 'superfat' => 5, 'water_mode' => 'percent_of_oils', 'water_value' => 38,
            'production_output_type' => ProductionOutputType::FinishedProduct->value, 'packaging_items' => [],
            'manufacturing_instructions' => '<p>Mix &amp; rest.</p>',
            'phases' => $familySlug === 'soap' ? [] : [['key' => 'phase_a', 'name' => 'Phase A'], ['key' => 'phase_b', 'name' => 'Phase B']],
            'phase_items' => $familySlug === 'soap'
                ? ['saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => '100', 'weight' => '1000.125', 'note' => 'Keep line note']], 'lye_water' => [['ingredient_id' => $liquid->id, 'percentage' => '25', 'weight' => '0']], 'additives' => [['ingredient_id' => $liquid->id, 'percentage' => '1.2345', 'weight' => '0']], 'fragrance' => [['ingredient_id' => $liquid->id, 'percentage' => '0.1234', 'weight' => '0']]]
                : ['phase_a' => [['ingredient_id' => $oil->id, 'percentage' => '70.1234', 'weight' => '0', 'note' => 'Keep line note']], 'phase_b' => [['ingredient_id' => $liquid->id, 'percentage' => '29.8766', 'weight' => '0']]],
        ];
        if ($weightBoundary) {
            $payload['editing_mode'] = 'weight';
            $payload['oil_weight'] = '1';
            if ($familySlug === 'soap') {
                $payload['phase_items']['saponified_oils'] = [['ingredient_id' => $oil->id, 'weight' => '0.500049'], ['ingredient_id' => $oil->id, 'weight' => '0.499951']];
                $payload['phase_items']['additives'] = [['ingredient_id' => $liquid->id, 'weight' => '0.000051'], ['ingredient_id' => $liquid->id, 'weight' => '0.000050']];
                $payload['phase_items']['fragrance'] = [['ingredient_id' => $liquid->id, 'weight' => '0.000049']];
            } else {
                $payload['phase_items']['phase_a'][0]['weight'] = '0.500049';
                $payload['phase_items']['phase_b'][0]['weight'] = '0.499951';
            }
        }
        if ($percentageBoundary) {
            $payload['oil_weight'] = '100000';
            if ($familySlug === 'soap') {
                $payload['phase_items']['saponified_oils'] = [['ingredient_id' => $oil->id, 'percentage' => '50.000049'], ['ingredient_id' => $oil->id, 'percentage' => '49.999951']];
            } else {
                $payload['phase_items']['phase_a'][0]['percentage'] = '50.000049';
                $payload['phase_items']['phase_b'][0]['percentage'] = '49.999951';
            }
        }
        if ($weightRows === 6) {
            $rows = array_fill(0, 6, ['ingredient_id' => $oil->id, 'weight' => '0.16666666666666667']);
            if ($familySlug === 'soap') {
                $payload['phase_items']['saponified_oils'] = $rows;
            } else {
                $payload['phase_items']['phase_a'] = array_slice($rows, 0, 3);
                $payload['phase_items']['phase_b'] = array_slice($rows, 3);
            }
        }
        if ($multiLiquid) {
            $payload['oil_weight'] = '1000.1256';
            $payload['phase_items']['lye_water'] = array_map(fn (string $percentage): array => ['ingredient_id' => $liquid->id, 'percentage' => $percentage], ['33.3333', '33.3333', '33.3334']);
        }
        $payload['water_mode'] = $waterMode;
        $payload['water_value'] = match ($waterMode) {
            'lye_ratio' => 2,
            'lye_concentration' => 33,
            default => 38,
        };
        $current = app(RecipeWorkbenchService::class)->publish($owner, $family, $payload);
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($current->recipe_id);
        $saved = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->firstOrFail();
        app(RecipeContentUpdater::class)->update($recipe, ['description' => '<p>Description &amp; details.</p>', 'manufacturing_instructions' => '<p>Mix &amp; rest.</p>']);
        $options ??= ['include_procedure' => true, 'include_description' => true, 'include_line_notes' => true];
        $builder = app(FormulaShareSnapshotBuilder::class);
        $snapshot = $builder->build($owner, $recipe, $options);
        $share = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, $options, $builder->previewHash($snapshot, $recipient), (string) Str::uuid());
        $plan->limits()->where('key', 'saved_recipes')->update(['value' => $recipeLimit]);
        $plan->limits()->where('key', 'private_ingredients')->update(['value' => $ingredientLimit]);

        return compact('owner', 'source', 'recipient', 'family', 'type', 'oil', 'liquid', 'recipe', 'saved', 'share', 'payload');
    }
}
