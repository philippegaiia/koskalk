<?php

use App\Models\IfraProductCategory;
use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('localizes curated Product taxonomy and IFRA content with an English fallback', function (): void {
    app()->setLocale('fr');

    $area = ProductArea::factory()->create([
        'name' => 'Personal care',
        'description' => 'Canonical description',
        'translations' => ['fr' => ['name' => 'Soins personnels']],
    ]);
    $category = ProductCategory::factory()->create([
        'name' => 'Skin care',
        'translations' => ['fr' => ['name' => 'Soins de la peau']],
    ]);
    $productType = ProductType::factory()->create([
        'name' => 'Face cream',
        'description' => 'Canonical type description',
        'translations' => ['fr' => ['name' => 'Crème pour le visage']],
    ]);
    $ifraCategory = IfraProductCategory::factory()->create([
        'code' => '5B',
        'name' => 'Face moisturizer products',
        'short_name' => 'Face moisturizer',
        'description' => 'Canonical IFRA description',
        'translations' => ['fr' => [
            'name' => 'Produits hydratants pour le visage',
            'short_name' => 'Hydratant visage',
            'description' => 'Produits sans rinçage appliqués sur le visage.',
        ]],
    ]);

    expect($area->localizedName())->toBe('Soins personnels')
        ->and($area->localizedDescription())->toBe('Canonical description')
        ->and($category->localizedName())->toBe('Soins de la peau')
        ->and($productType->localizedName())->toBe('Crème pour le visage')
        ->and($productType->localizedDescription())->toBe('Canonical type description')
        ->and($ifraCategory->localizedName())->toBe('Produits hydratants pour le visage')
        ->and($ifraCategory->localizedShortName())->toBe('Hydratant visage')
        ->and($ifraCategory->localizedDescription())->toBe('Produits sans rinçage appliqués sur le visage.')
        ->and($ifraCategory->optionLabel())->toBe('5B - Hydratant visage');
});
