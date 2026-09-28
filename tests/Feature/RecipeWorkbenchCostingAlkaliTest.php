<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\LyeLiquidAllocationService;
use App\Services\RecipeVersionCostingSynchronizer;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('prices the calculated alkali as a lye_alkali costing row for naoh soap', function () {
    $user = User::factory()->create();
    $naoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Potasse caustique');
    makeCostingAlkaliWaterIngredient();
    $oil = makeCostingAlkaliOilIngredient();

    $service = app(RecipeWorkbenchService::class);
    $soapFamily = makeCostingAlkaliSoapFamily();
    $draftVersion = $service->save($user, $soapFamily, costingAlkaliDraftPayload($oil));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $payload = $service->costingPayload($recipe, $user);

    $alkaliRows = collect($payload['item_prices'])->where('phase_key', 'lye_alkali')->values();

    expect($alkaliRows)->toHaveCount(1)
        ->and($alkaliRows[0]['ingredient_id'])->toBe($naoh->id)
        ->and($alkaliRows[0]['position'])->toBe(1)
        ->and($payload['alkali_ingredients']['naoh']['name'])->toBe('Soude caustique')
        ->and($payload['alkali_ingredients']['naoh']['ingredient_id'])->toBe($naoh->id);

    $waterRow = collect($payload['item_prices'])->firstWhere('phase_key', 'implicit_water');

    expect($waterRow['ingredient_id'])->toBe($payload['water_ingredient']['ingredient_id'])
        ->and($payload['water_ingredient']['name'])->toBe('Water');
});

it('does not persist an implicit water row when added liquids cover the full dilution', function () {
    $user = User::factory()->create();
    makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    $hydrosol = Ingredient::factory()->create([
        'display_name' => 'Lavender Hydrosol',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER',
        'is_active' => true,
    ]);
    makeCostingAlkaliWaterIngredient();
    $oil = makeCostingAlkaliOilIngredient();

    $soapFamily = makeCostingAlkaliSoapFamily();
    $payload = costingAlkaliDraftPayload($oil);
    $payload['phase_items']['lye_water'] = [
        [
            'ingredient_id' => $hydrosol->id,
            'percentage' => 100,
            'weight' => 380,
            'note' => null,
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, $payload);

    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($draftVersion, $user);

    expect($costing->items->where('phase_key', 'implicit_water'))->toBeEmpty()
        ->and($costing->items->where('phase_key', 'lye_water')->count())->toBe(1);
});

it('creates one alkali costing row per lye type in stable dual-lye order', function () {
    $user = User::factory()->create();
    $naoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    $koh = makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Potasse caustique');
    $oil = makeCostingAlkaliOilIngredient();

    $soapFamily = makeCostingAlkaliSoapFamily();
    $payload = costingAlkaliDraftPayload($oil);
    $payload['lye_type'] = 'dual';
    $payload['dual_lye_koh_percentage'] = 40;

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, $payload);

    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($draftVersion, $user);

    $alkaliRows = $costing->items->where('phase_key', 'lye_alkali')->sortBy('position')->values();

    expect($alkaliRows)->toHaveCount(2)
        ->and($alkaliRows[0]['ingredient_id'])->toBe($naoh->id)
        ->and($alkaliRows[0]['position'])->toBe(1)
        ->and($alkaliRows[1]['ingredient_id'])->toBe($koh->id)
        ->and($alkaliRows[1]['position'])->toBe(2);
});

it('projects saved alkali and implicit water rows with weighed KOH and saved prices without writes', function (): void {
    $user = User::factory()->create();
    $naoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Sodium hydroxide');
    $koh = makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Potassium hydroxide');
    $water = makeCostingAlkaliWaterIngredient();
    $hydrosol = Ingredient::factory()->create([
        'display_name' => 'Lavender hydrosol',
        'is_active' => true,
    ]);
    $oil = makeCostingAlkaliOilIngredient();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);
    $soapFamily = makeCostingAlkaliSoapFamily();
    $payload = costingAlkaliDraftPayload($oil);
    $payload['lye_type'] = 'dual';
    $payload['koh_purity_percentage'] = 100;
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => $hydrosol->id,
        'percentage' => 25,
        'weight' => 95,
        'note' => null,
    ]];

    $workbench = app(RecipeWorkbenchService::class);
    $version = $workbench->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($version, $user);
    $costing->fill([
        'oil_weight_for_costing' => '0.5',
        'oil_unit_for_costing' => 'kg',
        'oil_mass_grams_for_costing' => '500',
        'units_produced' => 2,
        'currency' => 'EUR',
    ])->save();
    $costing->items()->get()->each(function ($item) use ($naoh, $koh, $water, $hydrosol, $oil): void {
        $item->price_per_kg = match ((int) $item->ingredient_id) {
            $oil->id => '10',
            $hydrosol->id => '6',
            $naoh->id, $koh->id => '20',
            $water->id => '0',
            default => $item->price_per_kg,
        };
        $item->save();
    });
    $costing = $costing->fresh(['items', 'packagingItems']);
    $snapshot = $workbench->versionSnapshot($recipe, (int) $version->id);
    expect($snapshot['calculation'])->toBeArray();
    $allocation = app(LyeLiquidAllocationService::class)->allocateFresh(
        data_get($snapshot, 'calculation.lye.water.weight'),
        [['percentage' => 25]],
    );

    DB::flushQueryLog();
    DB::enableQueryLog();
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromCosting(
        recipe: $recipe,
        version: $version->fresh(),
        costing: $costing,
        batchBasisValue: '0.5',
        unitsProduced: 2,
        includeDerivedRows: true,
        requireCompletePackagingTotal: true,
        batchBasisUnit: 'kg',
    );
    $writes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^(insert|update|delete) /i', $query) === 1)
        ->all();
    DB::disableQueryLog();

    $ingredientRows = collect($preview['ingredient_rows']);
    $alkaliRows = $ingredientRows->where('phase_key', 'lye_alkali')->keyBy('ingredient_id');
    $rows = $ingredientRows->keyBy('phase_key');

    expect($writes)->toBe([])
        ->and($alkaliRows)->toHaveCount(2)
        ->and($alkaliRows[$naoh->id]['price_per_kg'])->toBe(20.0)
        ->and($alkaliRows[$koh->id]['ingredient_name'])->toBe(__('workbench.costing.ingredients.koh_with_purity', [
            'name' => 'Potassium hydroxide',
            'purity' => '100',
        ]))
        ->and($alkaliRows[$naoh->id]['quantity'])->toBe(round((float) data_get($snapshot, 'calculation.lye.selected.naoh_weight') / 2000, 8))
        ->and($alkaliRows[$koh->id]['quantity'])->toBe(round((float) data_get($snapshot, 'calculation.lye.selected.koh_to_weigh') / 2000, 8))
        ->and($rows['implicit_water']['ingredient_id'])->toBe($water->id)
        ->and($rows['implicit_water']['quantity'])->toBe(round((float) $allocation['water_weight'] / 2000, 8))
        ->and($rows['implicit_water']['price_per_kg'])->toBe(0.0)
        ->and($rows['lye_water']['quantity'])->toBe(0.0475)
        ->and($rows['lye_water']['percentage_basis'])->toBe('oils')
        ->and($rows['lye_water']['unit'])->toBe('kg')
        ->and($preview['currency'])->toBe('EUR');
});

it('keeps the implicit plain-water remainder visible when its saved price row is absent', function (): void {
    $user = User::factory()->create();
    $naoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Sodium hydroxide');
    $water = makeCostingAlkaliWaterIngredient();
    $oil = makeCostingAlkaliOilIngredient();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);
    $version = app(RecipeWorkbenchService::class)->save(
        $user,
        makeCostingAlkaliSoapFamily(),
        costingAlkaliDraftPayload($oil),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $costing = app(RecipeVersionCostingSynchronizer::class)->ensureCosting($version, $user);
    $costing->items()->where('phase_key', 'implicit_water')->delete();
    $costing = $costing->fresh(['items.ingredient', 'packagingItems.packagingItem']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromCosting(
            recipe: $recipe,
            version: $version->fresh(),
            costing: $costing,
            batchBasisValue: '1000',
            unitsProduced: 2,
            includeDerivedRows: true,
            requireCompletePackagingTotal: true,
            batchBasisUnit: 'g',
        );
        $writes = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query) === 1)
            ->all();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $waterRow = collect($preview['ingredient_rows'])->firstWhere('phase_key', 'implicit_water');
    $alkaliRow = collect($preview['ingredient_rows'])->firstWhere('phase_key', 'lye_alkali');

    expect($writes)->toBe([])
        ->and($waterRow['ingredient_id'])->toBe($water->id)
        ->and($waterRow['quantity'])->toBeGreaterThan(0)
        ->and($waterRow['price_per_kg'])->toBeNull()
        ->and($waterRow['is_unpriced'])->toBeTrue()
        ->and($alkaliRow['ingredient_id'])->toBe($naoh->id);
});

it('round-trips an alkali price through save and reconcile', function () {
    $user = User::factory()->create();
    $naoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Potasse caustique');
    $oil = makeCostingAlkaliOilIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, makeCostingAlkaliSoapFamily(), costingAlkaliDraftPayload($oil));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveCosting($user, $recipe, [
        'items' => [
            [
                'ingredient_id' => $naoh->id,
                'phase_key' => 'lye_alkali',
                'position' => 1,
                'price_per_kg' => 4.5,
            ],
        ],
        'packaging_items' => [],
    ]);

    app(RecipeVersionCostingSynchronizer::class)->reconcileExistingFormulaCosting($draftVersion, $user);

    $payload = $service->costingPayload($recipe, $user);
    $alkaliRow = collect($payload['item_prices'])
        ->first(fn (array $row): bool => $row['phase_key'] === 'lye_alkali' && $row['ingredient_id'] === $naoh->id);

    expect($alkaliRow['price_per_kg'])->toBe(4.5);
});

it('keeps the canonical alkali as the costing identity when a workspace duplicate exists', function () {
    $user = User::factory()->create();
    $canonicalNaoh = makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Public Caustic Soda');
    $canonicalKoh = makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Public Potash');
    $naohDuplicate = makeUserOwnedAlkaliIngredient($user, IngredientSubcategory::SodiumHydroxide, 'My Caustic Soda');
    $kohDuplicate = makeUserOwnedAlkaliIngredient($user, IngredientSubcategory::PotassiumHydroxide, 'My Potash');
    $oil = makeCostingAlkaliOilIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, makeCostingAlkaliSoapFamily(), costingAlkaliDraftPayload($oil));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $payload = $service->costingPayload($recipe, $user);

    expect($payload['alkali_ingredients']['naoh']['ingredient_id'])->toBe($canonicalNaoh->id)
        ->and($payload['alkali_ingredients']['naoh']['ingredient_id'])->not->toBe($naohDuplicate->id)
        ->and($payload['alkali_ingredients']['koh']['ingredient_id'])->toBe($canonicalKoh->id)
        ->and($payload['alkali_ingredients']['koh']['ingredient_id'])->not->toBe($kohDuplicate->id);

    $alkaliRow = collect($payload['item_prices'])
        ->first(fn (array $row): bool => $row['phase_key'] === 'lye_alkali');

    expect($alkaliRow['ingredient_id'])->toBe($canonicalNaoh->id);
});

it('prices a koh-only formula against the canonical CH3 identity at position 1', function () {
    $user = User::factory()->create();
    makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    $koh = makeCostingAlkaliIngredient(IngredientSubcategory::PotassiumHydroxide, 'Potasse caustique');
    $oil = makeCostingAlkaliOilIngredient();

    $soapFamily = makeCostingAlkaliSoapFamily();
    $payload = costingAlkaliDraftPayload($oil);
    $payload['lye_type'] = 'koh';

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $costingPayload = $service->costingPayload($recipe, $user);
    $alkaliRows = collect($costingPayload['item_prices'])->where('phase_key', 'lye_alkali')->values();

    expect($alkaliRows)->toHaveCount(1)
        ->and($alkaliRows[0]['ingredient_id'])->toBe($koh->id)
        ->and($alkaliRows[0]['position'])->toBe(1);
});

it('fails explicitly when the canonical alkali is missing instead of using a duplicate', function () {
    $user = User::factory()->create();
    makeUserOwnedAlkaliIngredient($user, IngredientSubcategory::SodiumHydroxide, 'My Caustic Soda');
    $oil = makeCostingAlkaliOilIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, makeCostingAlkaliSoapFamily(), costingAlkaliDraftPayload($oil));

    app(RecipeVersionCostingSynchronizer::class)->ensureCosting($draftVersion, $user);
})->throws(ValidationException::class);

it('adds no alkali rows to cosmetic costing', function () {
    $user = User::factory()->create();
    makeCostingAlkaliIngredient(IngredientSubcategory::SodiumHydroxide, 'Soude caustique');
    $water = Ingredient::factory()->create([
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);

    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $cosmeticFamily, [
        'name' => 'Cosmetic Alkali Check',
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [
            ['key' => 'hydration', 'name' => 'Hydration & Cool Down'],
        ],
        'phase_items' => [
            'hydration' => [
                [
                    'ingredient_id' => $water->id,
                    'percentage' => 100,
                    'weight' => 500,
                    'note' => null,
                ],
            ],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $payload = $service->costingPayload($recipe, $user);

    expect(collect($payload['item_prices'])->where('phase_key', 'lye_alkali'))->toBeEmpty()
        ->and(collect($payload['item_prices'])->where('phase_key', 'implicit_water'))->toBeEmpty()
        ->and($payload['alkali_ingredients'])->toBeEmpty()
        ->and($payload['water_ingredient'])->toBeNull();
});

function makeCostingAlkaliSoapFamily(): ProductFamily
{
    return ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
}

function makeCostingAlkaliIngredient(IngredientSubcategory $subcategory, string $displayName): Ingredient
{
    return Ingredient::factory()->create([
        'catalog_key' => $subcategory === IngredientSubcategory::SodiumHydroxide ? 'CH1' : 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => $subcategory,
        'display_name' => $displayName,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
}

function makeUserOwnedAlkaliIngredient(User $user, IngredientSubcategory $subcategory, string $displayName): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => $subcategory,
        'display_name' => $displayName,
        'is_active' => true,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
}

function makeCostingAlkaliWaterIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'subcategory' => IngredientSubcategory::Water,
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);
}

function makeCostingAlkaliOilIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function costingAlkaliDraftPayload(Ingredient $oil): array
{
    return [
        'name' => 'Alkali Costing Check',
        'product_type_id' => testProductTypeIdForFamily('soap'),
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $oil->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
