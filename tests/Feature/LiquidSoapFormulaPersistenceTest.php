<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Enums\OwnerType;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchPreviewService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function liquidSoapPersistenceOil(): Ingredient
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);

    return $oil;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer $neutralizer, array $attributes = []): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::PhAdjustersBuffers,
        'display_name' => $neutralizer->label(),
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'liquid_soap_neutralizer' => $neutralizer,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function liquidSoapFormulaPayload(Ingredient $oil, ProductType $productType, array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Liquid castile',
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'none',
        'editing_mode' => 'percentage',
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'lye_concentration',
        'water_value' => 28,
        'superfat' => -3,
        'liquid_process' => [
            'first_dilution_percentage' => 35,
            'final_dilution_percentage' => 25,
            'neutralizer_solution_percentage' => 20,
        ],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);
}

it('lets administrators mark a platform material as a liquid soap neutralizer', function () {
    $ingredient = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->fillForm(['liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($ingredient->refresh()->liquid_soap_neutralizer)->toBe(LiquidSoapNeutralizer::CitricAcidAnhydrous);
});

it('offers a material as a neutralizer only when it is a platform material', function () {
    $platform = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::LacticAcid);
    $workspaceCopy = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::LacticAcid, [
        'owner_type' => OwnerType::User,
        'owner_id' => User::factory()->create()->id,
    ]);

    expect($platform->liquidSoapNeutralizer())->toBe(LiquidSoapNeutralizer::LacticAcid)
        ->and($workspaceCopy->liquidSoapNeutralizer())->toBeNull();
});

it('offers a fatty acid as a neutralizer only with trusted soap chemistry', function (bool $trusted, ?LiquidSoapNeutralizer $expected) {
    $stearicAcid = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::FattyAcid, [
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => $trusted,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $stearicAcid->id, 'koh_sap_value' => 0.1972]);

    expect($stearicAcid->fresh()->liquidSoapNeutralizer())->toBe($expected);
})->with([
    'trusted SAP' => [true, LiquidSoapNeutralizer::FattyAcid],
    'untrusted SAP' => [false, null],
]);

it('adds a neutralization phase after the alkali solution only for liquid soap families', function () {
    $liquidFamily = ProductFamily::factory()->liquidSoapPaste()->create();

    $phases = app(RecipeWorkbenchService::class)->phaseBlueprints($liquidFamily);

    expect(collect($phases)->pluck('key')->all())->toBe(['saponified_oils', 'lye_water', 'neutralization', 'additives', 'fragrance'])
        ->and($phases[2]['phase_type'])->toBe('post_reaction');
});

it('saves and reloads a liquid soap formula with its process settings and neutralizer', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = liquidSoapPersistenceOil();
    $citricAcid = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::CitricAcidAnhydrous);
    $service = app(RecipeWorkbenchService::class);

    $version = $service->save($user, $family, liquidSoapFormulaPayload($oil, $productType, [
        'phase_items' => ['neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65, 'weight' => 6.5, 'note' => null]]],
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $payload = $service->versionPayload($recipe, $version->id);
    $storedVersion = RecipeVersion::withoutGlobalScopes()->findOrFail($version->id);

    expect($storedVersion->calculation_context['liquid_process'])->toBe([
        'first_dilution_percentage' => '35',
        'final_dilution_percentage' => '25',
        'neutralizer_solution_percentage' => '20',
    ])
        ->and($payload['liquidProcess'])->toBe([
            'firstDilutionPercentage' => 35.0,
            'finalDilutionPercentage' => 25.0,
            'neutralizerSolutionPercentage' => 20.0,
        ])
        ->and($payload['lyeType'])->toBe('koh')
        ->and($payload['superfat'])->toBe(-3.0)
        ->and($payload['phaseItems']['neutralization'])->toHaveCount(1)
        ->and($payload['phaseItems']['neutralization'][0]['ingredient_id'])->toBe($citricAcid->id)
        ->and((float) $payload['phaseItems']['neutralization'][0]['weight'])->toBe(6.5);
});

it('rejects liquid soap process settings that cannot be made', function (array $liquidProcess, string $message) {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapFormulaPayload(liquidSoapPersistenceOil(), $productType, ['liquid_process' => $liquidProcess]);

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['liquid_process'][0] ?? null)->toContain($message));
})->with([
    'final more concentrated than first' => [['first_dilution_percentage' => 30, 'final_dilution_percentage' => 35], 'must not be more concentrated'],
    'missing final dilution' => [['final_dilution_percentage' => null], 'Final dilution percentage'],
]);

it('rejects a liquid soap formula with more than one neutralizer', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapFormulaPayload(liquidSoapPersistenceOil(), $productType, [
        'phase_items' => ['neutralization' => [
            ['ingredient_id' => liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::CitricAcidAnhydrous)->id, 'percentage' => 0.5, 'weight' => 5, 'note' => null],
            ['ingredient_id' => liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::LacticAcid)->id, 'percentage' => 0.5, 'weight' => 5, 'note' => null],
        ]],
    ]);

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['neutralization'][0] ?? null)->toContain('one neutralizer'));
});

it('rejects a neutralizer row that is not a platform neutralizer', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $kaolin = Ingredient::factory()->create(['category' => IngredientCategory::MineralsSaltsPowders, 'owner_type' => null, 'owner_id' => null, 'is_active' => true]);
    $payload = liquidSoapFormulaPayload(liquidSoapPersistenceOil(), $productType, [
        'phase_items' => ['neutralization' => [['ingredient_id' => $kaolin->id, 'percentage' => 0.5, 'weight' => 5, 'note' => null]]],
    ]);

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['neutralization'][0] ?? null)->toContain('Choose a platform neutralizer'));
});

it('ignores liquid soap settings and neutralizer rows sent for a bar soap formula', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = liquidSoapPersistenceOil();
    $citricAcid = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::CitricAcidAnhydrous);

    $version = app(RecipeWorkbenchService::class)->save($user, $family, liquidSoapFormulaPayload($oil, $productType, [
        'superfat' => 5,
        'phase_items' => ['neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.5, 'weight' => 5, 'note' => null]]],
    ]));

    $storedVersion = RecipeVersion::withoutGlobalScopes()->with('phases')->findOrFail($version->id);

    expect($storedVersion->calculation_context)->not->toHaveKey('liquid_process')
        ->and($storedVersion->phases->pluck('slug')->all())->not->toContain('neutralization');
});

it('keeps a fatty acid neutralizer from absorbing lye in the paste calculation', function () {
    $oil = liquidSoapPersistenceOil();
    $stearicAcid = liquidSoapPlatformNeutralizer(LiquidSoapNeutralizer::FattyAcid, [
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $stearicAcid->id, 'koh_sap_value' => 0.1972]);
    $payload = [
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'editing_mode' => 'percentage',
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
        'water_mode' => 'lye_concentration',
        'water_value' => 28,
        'superfat' => -3,
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000]],
            'neutralization' => [['ingredient_id' => $stearicAcid->id, 'percentage' => 2.89, 'weight' => 28.9]],
        ],
    ];

    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation($payload);

    expect($calculation['lye']['excess']['koh_weight'])->toBe(5.7)
        ->and($calculation['lye']['post_reaction_oils_weight'])->toBe(0.0);
});

/**
 * A liquid soap recipe with a published version at 35/25 % and a current
 * draft that only changes the dilution targets to 40/30 %.
 *
 * @return array{user: User, recipe: Recipe, published: RecipeVersion}
 */
function liquidSoapRecipeWithChangedDraft(): array
{
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = liquidSoapPersistenceOil();
    $service = app(RecipeWorkbenchService::class);

    $draft = $service->save($user, $family, liquidSoapFormulaPayload($oil, $productType));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->saveAsNewVersion($user, $family, liquidSoapFormulaPayload($oil, $productType), $recipe);
    $service->save($user, $family, liquidSoapFormulaPayload($oil, $productType, [
        'liquid_process' => ['first_dilution_percentage' => 40, 'final_dilution_percentage' => 30],
    ]), $recipe);

    $published = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    return ['user' => $user, 'recipe' => $recipe->fresh(), 'published' => $published];
}

function liquidSoapCurrentProcess(Recipe $recipe): array
{
    return RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail()
        ->calculation_context['liquid_process'];
}

it('restores the liquid soap settings of a published version into the current draft', function () {
    ['user' => $user, 'recipe' => $recipe, 'published' => $published] = liquidSoapRecipeWithChangedDraft();

    app(RecipeWorkbenchService::class)->restoreCurrentVersion($user, $recipe, $published->id);

    expect(liquidSoapCurrentProcess($recipe))->toBe([
        'first_dilution_percentage' => '35',
        'final_dilution_percentage' => '25',
        'neutralizer_solution_percentage' => '20',
    ]);
});

it('republishes a liquid soap formula with its saved settings', function () {
    ['user' => $user, 'recipe' => $recipe, 'published' => $published] = liquidSoapRecipeWithChangedDraft();

    $republished = app(RecipeWorkbenchService::class)->restorePublishedFormula($user, $recipe, $published->id);

    expect($republished->is_current)->toBeFalse()
        ->and($republished->calculation_context['liquid_process'])->toBe([
            'first_dilution_percentage' => '35',
            'final_dilution_percentage' => '25',
            'neutralizer_solution_percentage' => '20',
        ]);
});

it('treats versions that differ only in liquid soap settings as different', function () {
    ['recipe' => $recipe, 'published' => $published] = liquidSoapRecipeWithChangedDraft();

    expect(app(RecipeWorkbenchService::class)->currentVersionWouldBeReplacedByVersion($recipe, $published->id))->toBeTrue();
});

it('copies the liquid soap settings when duplicating a recipe', function () {
    ['user' => $user, 'recipe' => $recipe] = liquidSoapRecipeWithChangedDraft();

    $duplicate = app(RecipeWorkbenchService::class)->duplicateRecipe($user, $recipe);

    expect(RecipeVersion::withoutGlobalScopes()->findOrFail($duplicate->id)->calculation_context['liquid_process'])->toBe([
        'first_dilution_percentage' => '40',
        'final_dilution_percentage' => '30',
        'neutralizer_solution_percentage' => '20',
    ]);
});

it('rejects a liquid soap formula made with an alkali other than KOH', function (string $lyeType) {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $payload = liquidSoapFormulaPayload(liquidSoapPersistenceOil(), $productType, ['lye_type' => $lyeType, 'superfat' => 3]);

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['lye_type'][0] ?? null)->toBe('A liquid soap paste uses KOH as its only alkali.'));
})->with(['naoh', 'dual']);
