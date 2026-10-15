<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\RecipeWorkbenchService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
    $this->seed(SupportedLocaleSeeder::class);
});

/**
 * A published liquid soap (1000 g oil at -3% superfat, citric acid as a 20%
 * solution, lavender as 2% of the final batch) and a recipient Workspace.
 *
 * @return array{owner: User, recipient: Workspace, recipe: Recipe, citricAcid: Ingredient}
 */
function sharedLiquidSoap(): array
{
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $recipient = Workspace::factory()->create();
    $plan = Plan::factory()->hasLimit('saved_recipes', 100)->hasLimit('private_ingredients', 100)->hasLimit('formula_items_per_recipe', 100)->hasLimit('saved_formula_history', 10)->create();
    foreach ([$owner, $recipient->owner] as $subscriber) {
        UserEntitlement::factory()->for($subscriber)->create(['plan_id' => $plan->id]);
    }
    Ingredient::factory()->create(['catalog_key' => 'CH3', 'category' => IngredientCategory::SoapmakingAlkalis]);
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $type = ProductType::factory()->create();
    $type->productFamilies()->attach($family->id);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'display_name' => 'Olive Oil', 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    $oil->sapProfile()->create(['koh_sap_value' => '0.190000']);
    $citricAcid = Ingredient::factory()->create(['category' => IngredientCategory::PhAdjustersBuffers, 'display_name' => 'Citric Acid', 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $lavender = Ingredient::factory()->create(['category' => IngredientCategory::AromaticMaterials, 'display_name' => 'Lavender Oil', 'is_active' => true]);
    $version = app(RecipeWorkbenchService::class)->publish($owner, $family, [
        'name' => 'Liquid castile', 'product_type_id' => $type->id, 'oil_unit' => 'g', 'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula', 'exposure_mode' => 'rinse_off', 'regulatory_regime' => 'none', 'editing_mode' => 'percentage',
        'lye_type' => 'koh', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40, 'water_mode' => 'lye_concentration', 'water_value' => 28, 'superfat' => -3,
        'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 6.5062, 'note' => null]],
            'additives' => [],
            'fragrance' => [['ingredient_id' => $lavender->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
        ],
        'packaging_items' => [],
    ]);

    return ['owner' => $owner, 'recipient' => $recipient, 'recipe' => Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id), 'citricAcid' => $citricAcid];
}

function sendAndAcceptLiquidSoap(User $owner, Recipe $recipe, Workspace $recipient): Recipe
{
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($owner, $recipe, []);
    $share = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $builder->previewHash($snapshot, $recipient), (string) Str::uuid());
    $preview = app(FormulaSharePreview::class)->build($recipient->owner, $share, []);

    return app(AcceptFormulaShare::class)->handle($recipient->owner, $share, [], $preview['expected_hash']);
}

function savedLiquidSoapVersion(Recipe $recipe): RecipeVersion
{
    return RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->latest('version_number')->firstOrFail();
}

it('round trips a liquid soap with its neutralizer and process settings', function (): void {
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe, 'citricAcid' => $citricAcid] = sharedLiquidSoap();

    $received = sendAndAcceptLiquidSoap($owner, $recipe, $recipient);
    $source = savedLiquidSoapVersion($recipe);
    $copy = savedLiquidSoapVersion($received);
    $neutralization = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $copy->id)->where('slug', 'neutralization')->sole();
    $workbench = app(RecipeWorkbenchService::class);
    $sourceProcess = $workbench->versionSnapshot($recipe, $source->id)['calculation']['liquid_process'];
    $receivedProcess = $workbench->versionSnapshot($received, $copy->id)['calculation']['liquid_process'];

    expect($received->workspace_id)->toBe($recipient->id)
        ->and($copy->calculation_context['liquid_process'])->toBe($source->calculation_context['liquid_process'])
        ->and(RecipeItem::withoutGlobalScopes()->where('recipe_phase_id', $neutralization->id)->get(['ingredient_id', 'percentage'])->toArray())
        ->toBe([['ingredient_id' => $citricAcid->id, 'percentage' => '0.6506']])
        ->and($receivedProcess['status'])->toBe('ok')
        ->and($receivedProcess['final_weight'])->toBe($sourceProcess['final_weight'])
        ->and($receivedProcess['neutralizer']['weight'])->toBe($sourceProcess['neutralizer']['weight']);
});

it('shows the liquid soap process settings in the offer', function (): void {
    ['owner' => $owner, 'recipe' => $recipe] = sharedLiquidSoap();

    $snapshot = app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []);

    expect($snapshot['formula']['calculation_context']['liquid_process'])->toBe([
        'first_dilution_percentage' => '35',
        'final_dilution_percentage' => '25',
        'neutralizer_solution_percentage' => '20',
    ])
        ->and(collect($snapshot['formula']['phases'])->pluck('slug')->all())->toContain('neutralization');
});

it('refuses to share a liquid soap saved without its process settings', function (): void {
    ['owner' => $owner, 'recipe' => $recipe] = sharedLiquidSoap();
    $version = savedLiquidSoapVersion($recipe);
    $context = $version->calculation_context;
    unset($context['liquid_process']);
    $version->forceFill(['calculation_context' => $context])->save();

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))
        ->toThrow(ValidationException::class, __('sharing.validation.settings'));
});
