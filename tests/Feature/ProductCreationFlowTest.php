<?php

use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\User;
use App\Services\ProductCreationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts new Products with exactly Soap, Cosmetics, and Home', function (): void {
    productCreationFixture();

    $this->actingAs(User::factory()->create())
        ->get(route('recipes.start'))
        ->assertSuccessful()
        ->assertSeeTextInOrder(['Soap', 'Oils + lye', 'Cosmetics', 'Skin, hair, melt-and-pour and syndets', 'Home', 'Candles, cleaning and laundry'])
        ->assertSee(route('recipes.choose-type', ['entry' => 'soap']))
        ->assertSee(route('recipes.choose-type', ['entry' => 'cosmetics']))
        ->assertSee(route('recipes.choose-type', ['entry' => 'home']))
        ->assertDontSee('IFRA');

    expect(app(ProductCreationCatalog::class)->entries())->toHaveCount(3);
});

it('groups compatible Product Types by area and category for each entry', function (): void {
    productCreationFixture();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('recipes.choose-type', ['entry' => 'soap']))
        ->assertSuccessful()
        ->assertSeeTextInOrder(['Personal care', 'Body cleansing', 'Bar soap / cleansing bar'])
        ->assertSeeTextInOrder(['Home & household', 'Laundry care', 'Hand-wash laundry soap'])
        ->assertDontSee('Face cream')
        ->assertDontSee('Candle / wax melt')
        ->assertDontSee('IFRA');

    $this->actingAs($user)
        ->get(route('recipes.choose-type', ['entry' => 'cosmetics']))
        ->assertSuccessful()
        ->assertSee('Face cream')
        ->assertDontSee('Hand-wash laundry soap')
        ->assertDontSee('Candle / wax melt');

    $this->actingAs($user)
        ->get(route('recipes.choose-type', ['entry' => 'home']))
        ->assertSuccessful()
        ->assertSee('Candle / wax melt')
        ->assertDontSee('Face cream')
        ->assertDontSee('Inactive home product')
        ->assertDontSee('Retired candle');
});

it('opens the Workbench only after a compatible Product Type is selected', function (): void {
    $fixture = productCreationFixture();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('recipes.create', [
            'family' => 'cosmetic',
            'type' => $fixture['faceCream']->slug,
        ]))
        ->assertSuccessful()
        ->assertSee('Face cream');

    $this->actingAs($user)
        ->get(route('recipes.create'))
        ->assertRedirect(route('recipes.choose-type', ['entry' => 'soap']));

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => 'cosmetic']))
        ->assertRedirect(route('recipes.choose-type', ['entry' => 'cosmetics']));

    $this->actingAs($user)
        ->get(route('recipes.choose-type', ['entry' => 'unknown']))
        ->assertNotFound();
});

/**
 * @return array{faceCream: ProductType}
 */
function productCreationFixture(): array
{
    $soapFamily = ProductFamily::factory()->create(['name' => 'Soap', 'slug' => 'soap']);
    $cosmeticFamily = ProductFamily::factory()->create(['name' => 'Cosmetic', 'slug' => 'cosmetic']);
    $personalArea = ProductArea::factory()->create([
        'name' => 'Personal care',
        'slug' => 'personal-care',
        'sort_order' => 10,
    ]);
    $homeArea = ProductArea::factory()->create([
        'name' => 'Home & household',
        'slug' => 'home-household',
        'sort_order' => 20,
    ]);
    $bodyCleansing = ProductCategory::factory()->create([
        'product_area_id' => $personalArea->id,
        'name' => 'Body cleansing',
        'slug' => 'body-cleansing',
        'sort_order' => 10,
    ]);
    $faceCare = ProductCategory::factory()->create([
        'product_area_id' => $personalArea->id,
        'name' => 'Face care',
        'slug' => 'face-care',
        'sort_order' => 20,
    ]);
    $laundryCare = ProductCategory::factory()->create([
        'product_area_id' => $homeArea->id,
        'name' => 'Laundry care',
        'slug' => 'laundry-care',
        'sort_order' => 10,
    ]);
    $homeFragrance = ProductCategory::factory()->create([
        'product_area_id' => $homeArea->id,
        'name' => 'Home fragrance',
        'slug' => 'home-fragrance',
        'sort_order' => 20,
    ]);
    $inactiveCategory = ProductCategory::factory()->create([
        'product_area_id' => $homeArea->id,
        'name' => 'Retired category',
        'slug' => 'retired-category',
        'is_active' => false,
    ]);

    ProductType::factory()->create([
        'product_family_id' => $soapFamily->id,
        'product_category_id' => $bodyCleansing->id,
        'name' => 'Bar soap / cleansing bar',
        'slug' => 'bar-soap-cleansing-bar',
        'sort_order' => 10,
    ]);
    ProductType::factory()->create([
        'product_family_id' => $soapFamily->id,
        'product_category_id' => $laundryCare->id,
        'name' => 'Hand-wash laundry soap',
        'slug' => 'hand-wash-laundry-soap',
        'sort_order' => 10,
    ]);
    $faceCream = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $faceCare->id,
        'name' => 'Face cream',
        'slug' => 'face-cream',
        'sort_order' => 10,
    ]);
    ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $homeFragrance->id,
        'name' => 'Candle / wax melt',
        'slug' => 'candle-wax-melt',
        'sort_order' => 10,
    ]);
    ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $homeFragrance->id,
        'name' => 'Inactive home product',
        'slug' => 'inactive-home-product',
        'is_active' => false,
    ]);
    ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $inactiveCategory->id,
        'name' => 'Retired candle',
        'slug' => 'retired-candle',
    ]);

    return ['faceCream' => $faceCream];
}
