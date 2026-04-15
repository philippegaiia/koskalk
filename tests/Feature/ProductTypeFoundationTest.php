<?php

use App\Filament\Resources\ProductTypes\Pages\EditProductType;
use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Models\IfraProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\ProductFamilySeeder;
use Database\Seeders\ProductTypeSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates the product types schema with recipe linkage', function () {
    expect(Schema::hasTable('product_types'))->toBeTrue()
        ->and(Schema::hasColumn('recipes', 'product_type_id'))->toBeTrue();
});

it('seeds the cosmetic product family and starter product types', function () {
    $this->seed([
        ProductFamilySeeder::class,
        ProductTypeSeeder::class,
    ]);

    $cosmeticFamily = ProductFamily::query()->where('slug', 'cosmetic')->first();

    expect($cosmeticFamily)->not->toBeNull()
        ->and($cosmeticFamily->calculation_basis)->toBe('total_formula')
        ->and(ProductType::query()->whereBelongsTo($cosmeticFamily)->pluck('slug')->sort()->values()->all())
        ->toBe([
            'balm-salve',
            'bath-salts-soaks',
            'cleansing-non-saponified',
            'cream-lotion',
            'deodorant',
            'hair-care',
            'lip-product',
            'mask',
            'oil-blend-serum',
            'other',
        ]);
});

it('keeps product types attached to a product family and optional IFRA default', function () {
    $family = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $ifraCategory = IfraProductCategory::factory()->create([
        'code' => '5A',
        'name' => 'Body lotion',
    ]);

    $type = ProductType::factory()
        ->for($family, 'productFamily')
        ->for($ifraCategory, 'defaultIfraProductCategory')
        ->create([
            'name' => 'Cream / lotion',
            'slug' => 'cream-lotion',
            'fallback_image_path' => 'product-types/cream-lotion.webp',
            'sort_order' => 10,
            'is_active' => true,
        ]);

    expect($type->productFamily->is($family))->toBeTrue()
        ->and($type->defaultIfraProductCategory->is($ifraCategory))->toBeTrue()
        ->and($type->fallback_image_path)->toBe('product-types/cream-lotion.webp');
});

it('can attach a product type to a recipe', function () {
    $productType = ProductType::factory()->create();

    $recipe = Recipe::factory()->create([
        'product_family_id' => $productType->product_family_id,
        'product_type_id' => $productType->id,
    ]);

    expect($recipe->productType->is($productType))->toBeTrue()
        ->and($productType->recipes()->withoutGlobalScopes()->whereKey($recipe->id)->exists())->toBeTrue();
});

it('renders the product type admin resource', function () {
    $user = User::factory()->admin()->create();
    $productType = ProductType::factory()->create([
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);

    $this->actingAs($user);

    $this->get(ProductTypeResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cream / lotion');

    $this->get(ProductTypeResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Product type identity')
        ->assertSee('Default IFRA category')
        ->assertSee('Fallback image');

    $this->get(ProductTypeResource::getUrl('edit', ['record' => $productType], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cream / lotion');
});

it('disables product type deletion when recipes reference it', function () {
    $user = User::factory()->admin()->create();
    $productType = ProductType::factory()->create();

    Recipe::factory()->create([
        'product_family_id' => $productType->product_family_id,
        'product_type_id' => $productType->id,
    ]);

    $this->actingAs($user);

    Livewire::test(EditProductType::class, ['record' => $productType->id])
        ->assertActionDisabled(DeleteAction::class);
});
