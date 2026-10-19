<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
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
use App\Services\IngredientShareProjector;
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
function sharedLiquidSoap(array $finalDilution = [], float $neutralizerDose = 6.5062): array
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
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => $neutralizerDose / 10, 'weight' => $neutralizerDose, 'note' => null]],
            'additives' => [],
            'fragrance' => [['ingredient_id' => $lavender->id, 'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => null]],
            'final_dilution' => $finalDilution,
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

it('round trips a liquid soap with its entered neutralizer and process settings', function (float $dose, string $share): void {
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe, 'citricAcid' => $citricAcid] = sharedLiquidSoap(neutralizerDose: $dose);

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
        ->toBe([['ingredient_id' => $citricAcid->id, 'percentage' => $share]])
        ->and($receivedProcess['status'])->toBe('ok')
        ->and($receivedProcess['final_weight'])->toBe($sourceProcess['final_weight'])
        ->and($receivedProcess['neutralizer']['weight'])->toBe($sourceProcess['neutralizer']['weight'])
        ->and((float) $receivedProcess['neutralizer']['weight'])->toBe($dose)
        ->and(RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $copy->id)->whereNotNull('final_percentage')->pluck('final_percentage')->all())->toBe(['2.0000']);
})->with(['near recommendation' => [6.5062, '0.6506'], 'above recommendation' => [10.0, '1.0000']]);

it('asks for a fresh preview when the neutralizer chemistry changed after it', function (): void {
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe, 'citricAcid' => $citricAcid] = sharedLiquidSoap();
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($owner, $recipe, []);
    $share = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $builder->previewHash($snapshot, $recipient), (string) Str::uuid());
    $preview = app(FormulaSharePreview::class)->build($recipient->owner, $share, []);

    $citricAcid->forceFill(['liquid_soap_neutralizer' => LiquidSoapNeutralizer::LacticAcid])->save();

    expect(fn () => app(AcceptFormulaShare::class)->handle($recipient->owner, $share, [], $preview['expected_hash']))
        ->toThrow(ValidationException::class);
    expect(collect(app(FormulaSharePreview::class)->build($recipient->owner, $share, [])['ingredients'])->firstWhere('name', 'Citric Acid')['warning'])
        ->toBe('platform_changed');
});

it('fingerprints the neutralizer type of platform neutralizers only', function (): void {
    ['citricAcid' => $citricAcid] = sharedLiquidSoap();
    $workspace = Workspace::factory()->create();
    $privateCopy = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'display_name' => 'Citric Acid copy', 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $projector = app(IngredientShareProjector::class);

    expect($projector->project($citricAcid)['technical']['liquid_soap_neutralizer'])->toBe('citric_acid_anhydrous')
        ->and($projector->project($privateCopy)['technical'])->not->toHaveKey('liquid_soap_neutralizer');
});

it('round trips liquid soap dilution liquids', function (): void {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe] = sharedLiquidSoap([['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]);

    $liquidRow = fn (RecipeVersion $version): RecipeItem => RecipeItem::withoutGlobalScopes()
        ->where('recipe_phase_id', RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('slug', 'final_dilution')->sole()->id)
        ->sole();
    $source = $liquidRow(savedLiquidSoapVersion($recipe));
    $copy = $liquidRow(savedLiquidSoapVersion(sendAndAcceptLiquidSoap($owner, $recipe, $recipient)));

    expect($copy->ingredient_id)->toBe($hydrosol->id)
        ->and($copy->percentage)->toBe('30.0000')
        ->and($copy->weight)->toBe($source->weight);
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

it('shares an addition at its saved share of the finished batch after the chemistry changed', function (): void {
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe] = sharedLiquidSoap();
    IngredientSapProfile::query()->update(['koh_sap_value' => 0.25]);

    $copy = savedLiquidSoapVersion(sendAndAcceptLiquidSoap($owner, $recipe, $recipient));

    expect(RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $copy->id)->whereNotNull('final_percentage')->pluck('final_percentage')->all())->toBe(['2.0000']);
});
