<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\ProductionOutputType;
use App\Enums\ProductionRunStatus;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\ProductSize;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductSizeResolver;
use App\Services\RecipeVersionCostingSynchronizer;
use App\Services\RecipeVersionViewDataBuilder;
use App\Services\RecipeWorkbenchPayloadNormalizer;
use App\Services\RecipeWorkbenchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores packaging rows as recipe version structure', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
    ]);
    $catalogItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Amber jar',
        'unit_cost' => 0.62,
        'currency' => 'EUR',
    ]);

    $row = $version->packagingItems()->create([
        'packaging_item_id' => $catalogItem->id,
        'name' => 'Amber jar',
        'components_per_unit' => 1,
        'notes' => '100 ml',
        'position' => 1,
    ]);

    expect($row->recipeVersion->is($version))->toBeTrue()
        ->and($row->packagingItem->is($catalogItem))->toBeTrue()
        ->and((float) $row->components_per_unit)->toBe(1.0)
        ->and($catalogItem->recipeVersionPackagingItems()->first()->is($row))->toBeTrue();
});

it('saves and publishes packaging rows with the recipe version', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);

    $payload = packagingPlanDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            [
                'packaging_item_id' => $packagingItem->id,
                'name' => 'Soap box',
                'components_per_unit' => 1,
                'notes' => 'Sleeve box',
            ],
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $payload, $recipe);

    $published = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($draft->fresh()->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems->first()->name)->toBe('Soap box')
        ->and((float) $published->packagingItems->first()->components_per_unit)->toBe(1.0)
        ->and($published->packagingItems->first()->notes)->toBe('Sleeve box');
});

it('puts saved packaging rows on one product size shared by the current and saved formula', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);

    $payload = packagingPlanDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            ['packaging_item_id' => $packagingItem->id, 'name' => 'Soap box', 'components_per_unit' => 1],
            ['packaging_item_id' => null, 'name' => 'Label', 'components_per_unit' => 1],
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $payload, $recipe);

    $size = $recipe->productSizes()->sole();
    $lineSizeIds = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->get()
        ->flatMap(fn (RecipeVersion $version) => $version->packagingItems->pluck('product_size_id'));

    expect($lineSizeIds)->toHaveCount(4)
        ->and($lineSizeIds->unique()->all())->toBe([$size->id]);
});

it('keeps unpackaged sizes available on their published formula and excludes sizes added later', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $base = packagingPlanDraftPayload(packagingPlanIngredient(), 'Unpackaged soap');
    $publishedKey = (string) str()->uuid();
    $draftKey = (string) str()->uuid();
    $publishedSize = productSizeRow($publishedKey, 'Small bar');
    $payload = $base + ['product_sizes' => [$publishedSize]];

    $workbench = app(RecipeWorkbenchService::class);
    $draft = $workbench->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $workbench->publish($user, $family, $payload, $recipe);
    $published = $recipe->publishedVersions()->firstOrFail();
    $workbench->save($user, $family, $base + [
        'product_sizes' => [$publishedSize, productSizeRow($draftKey, 'Large bar')],
    ], $recipe);

    $sizes = app(ProductSizeResolver::class);
    expect($sizes->selectForProduction($published)->size?->public_id)->toBe($publishedKey)
        ->and($sizes->forLatestPublishedVersion($recipe)->pluck('public_id')->all())->toBe([$publishedKey]);
    expect(fn () => $sizes->selectForProduction($published, $draftKey))->toThrow(ValidationException::class);
});

it('requires a new size when changing declared content after planning production', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $base = packagingPlanDraftPayload(packagingPlanIngredient(), 'Turmeric soap');
    $key = (string) str()->uuid();
    $payload = fn (float $content, string $name): array => $base + ['product_sizes' => [productSizeRow($key, $name, $content)]];
    $workbench = app(RecipeWorkbenchService::class);
    $draft = $workbench->save($user, $family, $payload(100, 'Small'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $size = $recipe->productSizes()->sole();
    ProductionRun::factory()->for($recipe)->create([
        'workspace_id' => $recipe->workspace_id,
        'recipe_version_id' => $draft->id,
        'product_size_id' => $size->id,
        'production_output_type' => ProductionOutputType::FinishedProduct,
        'status' => ProductionRunStatus::Scheduled,
    ]);

    expect(fn () => $workbench->save($user, $family, $payload(90, 'Small'), $recipe))
        ->toThrow(ValidationException::class, __('workbench.packaging.sizes.validation.in_use_content'));

    $workbench->save($user, $family, $payload(100, 'Small bar'), $recipe);
    expect($size->fresh()->name)->toBe('Small bar')
        ->and($size->fresh()->declared_content_value)->toBe('100.0000');

    $replacement = (string) str()->uuid();
    $workbench->save($user, $family, $base + [
        'product_sizes' => [productSizeRow($replacement, '90 g bar', 90)],
    ], $recipe);

    expect($recipe->productSizes()->sole()->public_id)->toBe($replacement)
        ->and($size->fresh()->archived_at)->not->toBeNull();
});

it('creates no product size for a formula saved without packaging', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $draft = app(RecipeWorkbenchService::class)
        ->save($user, $soapFamily, packagingPlanDraftPayload(packagingPlanIngredient(), 'Naked soap'));

    expect(Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id)->productSizes()->exists())->toBeFalse();
});

it('includes packaging rows in the workbench payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Wrap label',
        'unit_cost' => 0.08,
        'currency' => 'EUR',
    ]);

    $draft = app(RecipeWorkbenchService::class)->save($user, $soapFamily, packagingPlanDraftPayload($ingredient, 'Wrapped soap') + [
        'packaging_items' => [
            [
                'packaging_item_id' => $packagingItem->id,
                'name' => 'Wrap label',
                'components_per_unit' => 2,
                'notes' => 'Front and back',
            ],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);

    $payload = app(RecipeWorkbenchService::class)->currentVersionPayload($recipe);

    expect($payload['packagingItems'])->toHaveCount(1)
        ->and($payload['packagingItems'][0]['packaging_item_id'])->toBe($packagingItem->id)
        ->and($payload['packagingItems'][0]['name'])->toBe('Wrap label')
        ->and($payload['packagingItems'][0]['components_per_unit'])->toBe(2.0)
        ->and($payload['packagingItems'][0]['notes'])->toBe('Front and back');
});

it('renders packaging as its own workbench tab', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $productType = ProductType::factory()->create(['product_family_id' => $soapFamily->id]);

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => 'soap', 'type' => $productType->slug]))
        ->assertSuccessful()
        ->assertSee('Packaging')
        ->assertSee('Packaging plan')
        ->assertSee('Quantity per unit')
        ->assertSee('Effective unit price (GBP)')
        ->assertSeeHtml('data-search-combobox="packaging-catalog-search"')
        ->assertSee('aria-label="Search and add packaging item"', false)
        ->assertDontSee('aria-label="Search packaging items"', false);
});

it('shows packaging and batch use controls on the reference formula page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);
    $payload = packagingPlanDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            [
                'packaging_item_id' => $packagingItem->id,
                'name' => 'Soap box',
                'components_per_unit' => 1,
                'notes' => 'Sleeve box',
            ],
        ],
    ];
    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $payload, $recipe);

    $this->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Packaging plan')
        ->assertSee('Soap box')
        ->assertSee('1 per unit')
        ->assertSee('Record production')
        ->assertSee('Production batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Oil quantity')
        ->assertSee('Units produced');
});

function packagingPlanIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function packagingPlanDraftPayload(Ingredient $ingredient, string $name): array
{
    $soapFamily = ProductFamily::query()->where('slug', 'soap')->latest('id')->firstOrFail();
    $productType = ProductType::query()
        ->whereHas('productFamilies', fn (Builder $query): Builder => $query->whereKey($soapFamily->id))
        ->first();
    $productType ??= ProductType::factory()->create(['product_family_id' => $soapFamily->id]);

    return [
        'name' => $name,
        'product_type_id' => $productType->id,
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
                    'ingredient_id' => $ingredient->id,
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

function productSizeRow(string $key, ?string $name, ?float $value = null, string $unit = 'g'): array
{
    return ['key' => $key, 'name' => $name, 'declared_content_value' => $value, 'declared_content_unit' => $unit];
}

/**
 * @return array<int, string>
 */
function sizeValidationErrorKeys(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

function sizedPackagingRow(string $sizeKey, string $name): array
{
    return ['product_size_key' => $sizeKey, 'packaging_item_id' => null, 'name' => $name, 'components_per_unit' => 1];
}

it('saves several product sizes, each with its own packaging, and keeps the browser key as the size id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $large = '0b8a4f0e-5c39-4d8e-8f22-6f5b2a1c9d10';
    $small = '1c9b5a1f-6d4a-4e9f-9a33-7a6c3b2d0e21';

    $payload = packagingPlanDraftPayload(packagingPlanIngredient(), 'Turmeric soap') + [
        'product_sizes' => [productSizeRow($large, '100 g bar', 100), productSizeRow($small, '50 g bar', 50)],
        'packaging_items' => [
            sizedPackagingRow($large, 'Large box'),
            sizedPackagingRow($small, 'Small box'),
            sizedPackagingRow($small, 'Label'),
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $payload, $recipe);

    $sizes = $recipe->productSizes()->get();
    $workbench = $service->currentVersionPayload($recipe);

    expect($sizes->pluck('public_id')->all())->toBe([$large, $small])
        ->and($sizes->pluck('name')->all())->toBe(['100 g bar', '50 g bar'])
        ->and($sizes->first()->declared_content_value)->toBe('100.0000')
        ->and($sizes->first()->packagingItems()->count())->toBe(2)
        ->and($sizes->last()->packagingItems()->count())->toBe(4)
        ->and(collect($workbench['productSizes'])->pluck('key')->all())->toBe([$large, $small])
        ->and(collect($workbench['packagingItems'])->pluck('product_size_key')->all())->toBe([$large, $small, $small]);
});

it('archives a removed size that saved history still uses and deletes one that nothing uses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = packagingPlanIngredient();
    $kept = '2d0c6b2a-7e5b-4fa0-8b44-8b7d4c3e1f32';
    $published = '3e1d7c3b-8f6c-4ab1-9c55-9c8e5d4f2a43';
    $unpublished = '4f2e8d4c-9a7d-4bc2-8d66-ad9f6e5a3b54';

    $service = app(RecipeWorkbenchService::class);
    $base = packagingPlanDraftPayload($ingredient, 'Turmeric soap');
    $draft = $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($kept, 'Kept'), productSizeRow($published, 'Published')],
        'packaging_items' => [sizedPackagingRow($kept, 'Box'), sizedPackagingRow($published, 'Jar')],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($kept, 'Kept'), productSizeRow($published, 'Published')],
        'packaging_items' => [sizedPackagingRow($kept, 'Box'), sizedPackagingRow($published, 'Jar')],
    ], $recipe);
    $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($kept, 'Kept'), productSizeRow($unpublished, 'Never published')],
        'packaging_items' => [sizedPackagingRow($kept, 'Box'), sizedPackagingRow($unpublished, 'Tin')],
    ], $recipe);

    $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($kept, 'Kept')],
        'packaging_items' => [sizedPackagingRow($kept, 'Box')],
    ], $recipe);

    expect($recipe->productSizes()->pluck('public_id')->all())->toBe([$kept])
        ->and(ProductSize::query()->where('public_id', $published)->first()?->archived_at)->not->toBeNull()
        ->and(ProductSize::query()->where('public_id', $unpublished)->exists())->toBeFalse();
});

it('brings an archived size back when restoring saved history, without removing sizes added since', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = packagingPlanIngredient();
    $old = '5a3f9e5d-0b8e-4cd3-9e77-be0a7f6b4c65';
    $new = '6b4a0f6e-1c9f-4de4-8f88-cf1b8a7c5d76';

    $service = app(RecipeWorkbenchService::class);
    $base = packagingPlanDraftPayload($ingredient, 'Turmeric soap');
    $oldPayload = $base + ['product_sizes' => [productSizeRow($old, 'Old')], 'packaging_items' => [sizedPackagingRow($old, 'Old box')]];
    $draft = $service->save($user, $soapFamily, $oldPayload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->publish($user, $soapFamily, $oldPayload, $recipe);
    $oldVersion = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->latest('version_number')->firstOrFail();
    $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($new, 'New')],
        'packaging_items' => [sizedPackagingRow($new, 'New box')],
    ], $recipe);

    $service->restorePublishedFormula($user, $recipe->fresh(), $oldVersion->id);

    expect($recipe->productSizes()->pluck('public_id')->all())->toBe([$old, $new]);
});

it('gives a duplicated formula its own sizes instead of reusing the source product\'s', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = packagingPlanIngredient();
    $key = '7c5b1a7f-2d0a-4ef5-9a99-d02c9b8d6e87';
    $payload = packagingPlanDraftPayload($ingredient, 'Turmeric soap') + [
        'product_sizes' => [productSizeRow($key, '100 g bar', 100)],
        'packaging_items' => [sizedPackagingRow($key, 'Box')],
    ];

    $service = app(RecipeWorkbenchService::class);
    $source = Recipe::withoutGlobalScopes()->findOrFail($service->save($user, $soapFamily, $payload)->recipe_id);
    $copyDraft = $service->save($user, $soapFamily, ['name' => 'Turmeric soap copy'] + $payload);
    $copy = Recipe::withoutGlobalScopes()->findOrFail($copyDraft->recipe_id);
    $service->publish($user, $soapFamily, ['name' => 'Turmeric soap copy'] + $payload, $copy);

    $copySize = $copy->productSizes()->sole();

    expect($copy->id)->not->toBe($source->id)
        ->and($source->productSizes()->sole()->public_id)->toBe($key)
        ->and($copySize->public_id)->not->toBe($key)
        ->and($copySize->name)->toBe('100 g bar')
        ->and($copySize->packagingItems()->pluck('recipe_version_id')->unique()->count())->toBe(2);
});

it('keeps each size\'s packaging price when another size using the same item is removed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    seedCanonicalAlkalis();
    $large = '8d6c2b8a-3e1b-4fa6-8baa-e13dac9e7f98';
    $small = '9e7d3c9b-4f2c-4ab7-9cbb-f24ebdaf8a09';
    $service = app(RecipeWorkbenchService::class);
    $base = packagingPlanDraftPayload(packagingPlanIngredient(), 'Turmeric soap');
    $draft = $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($large, 'Large'), productSizeRow($small, 'Small')],
        'packaging_items' => [sizedPackagingRow($large, 'Label'), sizedPackagingRow($small, 'Label')],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    app(RecipeVersionCostingSynchronizer::class)->save($draft, $user, [
        'units_produced' => 20,
        'packaging_items' => [
            ['product_size_key' => $large, 'name' => 'Label', 'unit_cost' => 0.10, 'components_per_unit' => 1],
            ['product_size_key' => $small, 'name' => 'Label', 'unit_cost' => 0.80, 'components_per_unit' => 1],
        ],
    ]);

    $service->save($user, $soapFamily, $base + [
        'product_sizes' => [productSizeRow($small, 'Small')],
        'packaging_items' => [sizedPackagingRow($small, 'Label')],
    ], $recipe);

    $prices = RecipeVersionCosting::query()->where('recipe_version_id', $draft->id)->sole()->packagingItems()->get();

    expect($prices)->toHaveCount(1)
        ->and($prices->first()->unit_cost)->toBe('0.8000')
        ->and($prices->first()->product_size_id)->toBe(ProductSize::query()->where('public_id', $small)->value('id'));
});

it('gives a duplicated product its source prices and yields on its own sizes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    seedCanonicalAlkalis();
    $large = 'b19f5e1d-6b4e-4cd9-8edd-146fdb1ac2b2';
    $small = 'c2a06f2e-7c5f-4dea-9fee-2570ec2bd3c3';
    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $soapFamily, packagingPlanDraftPayload(packagingPlanIngredient(), 'Turmeric soap') + [
        'product_sizes' => [productSizeRow($large, 'Large'), productSizeRow($small, 'Small')],
        'packaging_items' => [sizedPackagingRow($large, 'Label'), sizedPackagingRow($small, 'Label')],
    ]);
    app(RecipeVersionCostingSynchronizer::class)->save($draft, $user, [
        'units_produced' => 20,
        'units_produced_by_size' => [$large => 20, $small => 40],
        'packaging_items' => [
            ['product_size_key' => $large, 'name' => 'Label', 'unit_cost' => 1, 'components_per_unit' => 1],
            ['product_size_key' => $small, 'name' => 'Label', 'unit_cost' => 2, 'components_per_unit' => 1],
        ],
    ]);

    $duplicate = $service->duplicateRecipe($user, Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id));

    $copySizeIds = ProductSize::query()->where('recipe_id', $duplicate->recipe_id)->orderBy('position')->pluck('id')->all();
    $prices = RecipeVersionCosting::query()->where('recipe_version_id', $duplicate->id)->sole()
        ->packagingItems()->orderBy('product_size_id')->get();

    expect($prices->map(fn ($row): array => [$row->product_size_id, $row->unit_cost])->all())
        ->toBe([[$copySizeIds[0], '1.0000'], [$copySizeIds[1], '2.0000']]);
    $copy = Recipe::withoutGlobalScopes()->findOrFail($duplicate->recipe_id);
    $copyKeys = $copy->productSizes()->pluck('public_id')->all();
    expect(app(RecipeVersionCostingSynchronizer::class)->payload($copy, $user)['settings']['unitsProducedBySize'])
        ->toEqual([$copyKeys[0] => 20, $copyKeys[1] => 40]);
});

it('uses the selected size yield and packaging for saved formula output', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    seedCanonicalAlkalis();
    $large = 'b19f5e1d-6b4e-4cd9-8edd-146fdb1ac2b2';
    $small = 'c2a06f2e-7c5f-4dea-9fee-2570ec2bd3c3';
    $payload = packagingPlanDraftPayload(packagingPlanIngredient(), 'Sized soap') + [
        'product_sizes' => [productSizeRow($large, 'Large', 100), productSizeRow($small, 'Small', 75)],
        'packaging_items' => [sizedPackagingRow($large, 'Large box'), sizedPackagingRow($small, 'Small box')],
    ];
    $service = app(RecipeWorkbenchService::class);
    $draft = $service->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    app(RecipeVersionCostingSynchronizer::class)->save($draft, $user, [
        'units_produced_by_size' => [$large => 8, $small => 15],
        'packaging_items' => [
            ['product_size_key' => $large, 'name' => 'Large box', 'unit_cost' => 1, 'components_per_unit' => 1],
            ['product_size_key' => $small, 'name' => 'Small box', 'unit_cost' => 2, 'components_per_unit' => 1],
        ],
    ]);
    $service->publish($user, $family, $payload, $recipe);
    $saved = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->sole();
    $view = app(RecipeVersionViewDataBuilder::class);
    $largeCosts = $view->build($recipe, $saved)['costingProjection'];
    $smallView = $view->build($recipe, $saved, batchContext: ['product_size' => $small]);
    $smallCosts = $smallView['costingProjection'];

    expect($largeCosts['units_produced'])->toEqual(8)
        ->and(array_column($largeCosts['packaging_rows'], 'name'))->toBe(['Large box'])
        ->and($largeCosts['packaging_total'])->toEqual(8)
        ->and($smallCosts['units_produced'])->toEqual(15)
        ->and(array_column($smallCosts['packaging_rows'], 'name'))->toBe(['Small box'])
        ->and($smallCosts['packaging_total'])->toEqual(30)
        ->and(array_column($smallView['costingExportData']['packagingRows'], 'packaging'))->toBe(['Small box']);

    $route = ['recipe' => $recipe, 'version' => $saved, 'product_size' => $small];
    $this->get(route('recipes.version', $route))
        ->assertOk()
        ->assertSee(__('workbench.costing.packaging.size', ['size' => 'Small']))
        ->assertSee('30 EUR');
    $this->get(route('recipes.print.recipe', $route + ['sections' => ['costing']]))
        ->assertOk()
        ->assertSee('Small box')
        ->assertDontSee('Large box');
    $workbook = $this->get(route('recipes.export.xlsx', $route + ['kind' => 'costing']))
        ->assertOk()
        ->assertDownload()
        ->streamedContent();
    expect($workbook)->toStartWith('PK');

    $current = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->sole();
    expect(sizeValidationErrorKeys(fn () => app(RecipeVersionCostingSynchronizer::class)->save($current, $user, [
        'units_produced_by_size' => [$small => 2.5],
    ])))->toBe(['units_produced_by_size']);
    expect($view->build($recipe, $saved, batchContext: ['product_size' => $small])['costingProjection']['units_produced'])
        ->toEqual(15);
});

it('keeps removed size yields in saved history and brings them back when restoring', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    seedCanonicalAlkalis();
    $first = 'b19f5e1d-6b4e-4cd9-8edd-146fdb1ac2b2';
    $second = 'c2a06f2e-7c5f-4dea-9fee-2570ec2bd3c3';
    $base = packagingPlanDraftPayload(packagingPlanIngredient(), 'Unboxed soap');
    $payload = $base + ['product_sizes' => [productSizeRow($first, 'Large'), productSizeRow($second, 'Small')]];
    $workbench = app(RecipeWorkbenchService::class);
    $synchronizer = app(RecipeVersionCostingSynchronizer::class);
    $draft = $workbench->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $synchronizer->save($draft, $user, ['units_produced_by_size' => [$first => 12, $second => 15]]);
    $synchronizer->save($draft, $user, ['units_produced' => 13]);
    $workbench->publish($user, $family, $payload, $recipe);
    $saved = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->sole();

    $draft = $workbench->save($user, $family, $base + ['product_sizes' => [productSizeRow($second, 'Small')]], $recipe);
    $history = RecipeVersionCosting::query()->where('recipe_version_id', $saved->id)->sole()->sizeYields;
    expect($synchronizer->payload($recipe, $user)['settings']['unitsProducedBySize'])->toBe([$second => 15])
        ->and($history->firstWhere('public_id', $first)->pivot->units_produced)->toEqual(13);

    $restored = $workbench->restoreCurrentVersion($user, $recipe, $saved->id);
    expect($restored->id)->toBe($draft->id)
        ->and($recipe->productSizes()->where('public_id', $first)->exists())->toBeTrue()
        ->and($synchronizer->payload($recipe, $user)['settings']['unitsProducedBySize'])->toEqual([$first => 13, $second => 15]);
});

it('refuses more product sizes than the limit instead of moving their packaging', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $sizes = collect(range(1, RecipeWorkbenchPayloadNormalizer::MaxProductSizes + 1))
        ->map(fn (int $number): array => productSizeRow(sprintf('00000000-0000-4000-8000-%012d', $number), "Size {$number}"));

    expect(sizeValidationErrorKeys(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, packagingPlanDraftPayload(packagingPlanIngredient(), 'Many sizes') + [
        'product_sizes' => $sizes->all(),
        'packaging_items' => [sizedPackagingRow($sizes->last()['key'], 'Box')],
    ])))->toBe(['product_sizes']);

    expect(ProductSize::query()->count())->toBe(0);
});

it('refuses a packaging line that names a size the formula does not have', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $key = 'a08e4d0c-5a3d-4bc8-8dcc-035fcea09b1a';

    expect(sizeValidationErrorKeys(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, packagingPlanDraftPayload(packagingPlanIngredient(), 'Soap') + [
        'product_sizes' => [productSizeRow($key, 'Bar')],
        'packaging_items' => [sizedPackagingRow($key, 'Box'), sizedPackagingRow('b19f5e1d-6b4e-4cd9-9edd-146adfb1ac2b', 'Lost box')],
    ])))->toBe(['packaging_items'])
        ->and(ProductSize::query()->count())->toBe(0);
});

it('checks declared content after rounding it to the stored four decimals', function (string $value, ?string $stored) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $key = 'c2a06f2e-7c5f-4dea-8fee-257bea0c2d3c';
    $save = fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, packagingPlanDraftPayload(packagingPlanIngredient(), 'Soap') + [
        'product_sizes' => [['key' => $key, 'name' => 'Bar', 'declared_content_value' => $value, 'declared_content_unit' => 'g']],
        'packaging_items' => [],
    ]);

    if ($stored === null) {
        expect(sizeValidationErrorKeys($save))->toBe(['product_sizes.0.declared_content_value']);

        return;
    }

    $save();

    expect(ProductSize::query()->where('public_id', $key)->value('declared_content_value'))->toBe($stored);
})->with([
    'largest storable value' => ['99999999.9999', '99999999.9999'],
    'rounds above the column' => ['99999999.99996', null],
    'rounds down to zero' => ['0.00001', null],
    'not a number' => ['heavy', null],
    'number followed by a unit' => ['30 ml', null],
    'scientific notation' => ['1e2', null],
]);

function seedCanonicalAlkalis(): void
{
    foreach (['CH1' => IngredientSubcategory::SodiumHydroxide, 'CH3' => IngredientSubcategory::PotassiumHydroxide] as $catalogKey => $subcategory) {
        Ingredient::factory()->create([
            'catalog_key' => $catalogKey,
            'category' => IngredientCategory::SoapmakingAlkalis,
            'subcategory' => $subcategory,
            'display_name' => $subcategory->name,
            'owner_type' => null,
            'owner_id' => null,
            'workspace_id' => null,
            'is_active' => true,
        ]);
    }
}
