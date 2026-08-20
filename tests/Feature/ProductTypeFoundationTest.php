<?php

use App\Filament\Resources\ProductTypes\Pages\CreateProductType;
use App\Filament\Resources\ProductTypes\Pages\EditProductType;
use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MediaStorage;
use Database\Seeders\ProductFamilySeeder;
use Database\Seeders\ProductTypeSeeder;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates the product types schema with recipe linkage', function () {
    expect(Schema::hasTable('product_types'))->toBeTrue()
        ->and(Schema::hasColumn('recipes', 'product_type_id'))->toBeTrue();
});

it('seeds the cosmetic product family and canonical product types', function () {
    $this->seed([
        ProductFamilySeeder::class,
        ProductTypeSeeder::class,
    ]);

    $cosmeticFamily = ProductFamily::query()->where('slug', 'cosmetic')->first();

    expect($cosmeticFamily)->not->toBeNull()
        ->and($cosmeticFamily->calculation_basis)->toBe('total_formula')
        ->and(ProductType::query()->where('is_active', true)->count())->toBe(45)
        ->and($cosmeticFamily->productTypes()->whereIn('slug', [
            'bar-soap-cleansing-bar',
            'shampoo',
            'candle-wax-melt',
            'other-cosmetics',
            'other-home-product',
        ])->count())->toBe(5);
});

it('classifies product types by category and compatible calculation families', function () {
    $soapFamily = ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
    ]);
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $area = ProductArea::factory()->create(['name' => 'Personal care']);
    $category = ProductCategory::factory()->create([
        'product_area_id' => $area->id,
        'name' => 'Body cleansing',
    ]);

    $type = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $category->id,
        'name' => 'Cleansing bar',
        'slug' => 'cleansing-bar',
        'fallback_image_path' => 'product-types/cleansing-bar.webp',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $type->productFamilies()->sync([$soapFamily->id, $cosmeticFamily->id]);

    expect($type->productCategory->is($category))->toBeTrue()
        ->and($type->productCategory->productArea->is($area))->toBeTrue()
        ->and($type->productFamilies->pluck('id')->all())->toEqualCanonicalizing([$soapFamily->id, $cosmeticFamily->id])
        ->and($type->fallback_image_path)->toBe('product-types/cleansing-bar.webp');
});

it('creates a product type with a category and multiple compatible families from admin', function (): void {
    $admin = User::factory()->admin()->create();
    $area = ProductArea::factory()->create(['name' => 'Personal care']);
    $category = ProductCategory::factory()->create([
        'product_area_id' => $area->id,
        'name' => 'Body cleansing',
    ]);
    $families = ProductFamily::factory()->count(2)->create();
    $this->actingAs($admin);

    Livewire::test(CreateProductType::class)
        ->fillForm([
            'product_category_id' => $category->id,
            'productFamilies' => $families->modelKeys(),
            'name' => 'Cleansing bar',
            'slug' => 'cleansing-bar',
            'sort_order' => 10,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $productType = ProductType::query()->where('slug', 'cleansing-bar')->firstOrFail();

    expect($productType->product_category_id)->toBe($category->id)
        ->and($productType->productFamilies->modelKeys())->toEqualCanonicalizing($families->modelKeys());
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
        ->assertSee('Product category')
        ->assertSee('Compatible families')
        ->assertDontSee('Default IFRA category')
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

it('keeps the original fallback image name in the product type form and uses a neutral legacy display name', function () {
    config(['media.disk' => 'local']);
    Storage::fake(MediaStorage::publicDisk());

    $admin = User::factory()->admin()->create();
    $productType = ProductType::factory()->create();
    $path = 'product-types/fallback-images/01JQ3RANDOM.webp';
    $productType->update(['fallback_image_path' => $path]);

    $this->actingAs($admin);

    $field = Livewire::test(EditProductType::class, ['record' => $productType->id])
        ->instance()
        ->form
        ->getComponent('fallback_image_path');
    Storage::disk($field->getDiskName())->put($path, 'public-image');
    $field->rawState([$path => $path]);

    expect($field)->toBeInstanceOf(FileUpload::class)
        ->and($field->getFileNamesStatePath())->toEndWith('fallback_image_original_name')
        ->and(array_values($field->getUploadedFiles())[0]['name'])->toBe('Current image')
        ->and(array_values($field->getUploadedFiles())[0]['name'])->not->toBe(basename($path));
});
