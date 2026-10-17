<?php

use App\Enums\ProductTypeIllustration;
use App\Models\ProductType;
use App\Services\ProductCreationCatalog;
use Database\Seeders\ProductFamilySeeder;
use Database\Seeders\ProductTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ships a drawing file for every illustration', function (ProductTypeIllustration $illustration): void {
    expect(public_path("images/app/product-types/{$illustration->value}.svg"))->toBeFile();
})->with(ProductTypeIllustration::cases());

it('gives every seeded Product Type its own drawing', function (): void {
    $this->seed([ProductFamilySeeder::class, ProductTaxonomySeeder::class]);

    $unmappedSlugs = ProductType::query()
        ->where('is_active', true)
        ->where('slug', '!=', 'other-cosmetics')
        ->pluck('slug')
        ->filter(fn (string $slug): bool => ProductTypeIllustration::resolve($slug) === ProductTypeIllustration::Sparkle)
        ->values()
        ->all();

    expect($unmappedSlugs)->toBe([]);
});

it('falls back to the category, then the area, for a Product Type added later', function (): void {
    expect(ProductTypeIllustration::resolve('wool-wash', 'laundry', 'home-household'))->toBe(ProductTypeIllustration::WashingMachine)
        ->and(ProductTypeIllustration::resolve('drawer-sachet', 'other', 'home-household'))->toBe(ProductTypeIllustration::House)
        ->and(ProductTypeIllustration::resolve('beard-wax', 'other', 'personal-care'))->toBe(ProductTypeIllustration::Sparkle)
        ->and(ProductTypeIllustration::resolve(null))->toBe(ProductTypeIllustration::Sparkle);
});

it('offers each Product Type in the pickers with its drawing', function (): void {
    $this->seed([ProductFamilySeeder::class, ProductTaxonomySeeder::class]);

    $barSoap = collect(app(ProductCreationCatalog::class)->types())
        ->firstWhere('slug', 'bar-soap-cleansing-bar');

    expect($barSoap['illustration_url'])->toBe(ProductTypeIllustration::BarSoap->url());
});
