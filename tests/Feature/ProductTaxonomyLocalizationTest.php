<?php

use App\Models\IfraProductCategory;
use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Database\Seeders\ProductFamilySeeder;
use Database\Seeders\ProductTaxonomySeeder;
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

it('seeds translated names for every curated product type category and area', function (): void {
    $this->seed(ProductFamilySeeder::class);
    $this->seed(ProductTaxonomySeeder::class);
    expect(ProductType::query()->where('is_active', true)->count())->toBe(45);
    foreach ([ProductArea::class, ProductCategory::class, ProductType::class] as $model) {
        foreach ($model::query()->where('is_active', true)->get() as $record) {
            foreach (['fr', 'de', 'es', 'it', 'nl', 'pt_BR'] as $locale) {
                expect(data_get($record->translations, $locale.'.name'))->toBeString()->not->toBeEmpty();
            }
        }
    }
    expect(ProductType::query()->where('slug', 'bar-soap-cleansing-bar')->firstOrFail()->localizedName('de'))->toBe('Stückseife / Waschstück');
    expect(ProductArea::query()->where('slug', 'personal-care')->firstOrFail()->localizedName('es'))->toBe('Cuidado personal');
});

it('backfills missing taxonomy names without replacing edited labels or custom taxonomy', function (): void {
    $area = ProductArea::factory()->create(['slug' => 'personal-care', 'name' => 'Edited canonical area', 'translations' => ['fr' => ['name' => 'Libellé personnalisé'], 'de' => ['name' => 'Eigener Bereich', 'description' => 'Keep this description']]]);
    $category = ProductCategory::factory()->create(['product_area_id' => $area->id, 'slug' => 'other']);
    $otherArea = ProductArea::factory()->create(['slug' => 'home-household']);
    $otherCategory = ProductCategory::factory()->create(['product_area_id' => $otherArea->id, 'slug' => 'other']);
    $type = ProductType::factory()->create(['slug' => 'bar-soap-cleansing-bar', 'name' => 'Edited canonical type', 'translations' => ['fr' => ['name' => 'Libellé type personnalisé'], 'es' => ['name' => '']]]);
    $customType = ProductType::factory()->create(['slug' => 'my-custom-type', 'translations' => ['fr' => ['name' => 'Type privé']]]);
    $customAreaSlugType = ProductType::factory()->create(['slug' => 'personal-care', 'translations' => ['fr' => ['name' => 'Type spécifique']]]);

    $this->artisan('taxonomy:backfill-translations', ['--dry-run' => true])->assertSuccessful();
    expect($type->fresh()->translations)->toBe(['fr' => ['name' => 'Libellé type personnalisé'], 'es' => ['name' => '']]);
    $this->artisan('taxonomy:backfill-translations')->assertSuccessful();
    expect($area->fresh()->name)->toBe('Edited canonical area')
        ->and($area->fresh()->localizedName('fr'))->toBe('Libellé personnalisé')
        ->and($area->fresh()->localizedName('de'))->toBe('Eigener Bereich')
        ->and($area->fresh()->localizedDescription('de'))->toBe('Keep this description');
    expect($type->fresh()->name)->toBe('Edited canonical type')
        ->and($type->fresh()->localizedName('fr'))->toBe('Libellé type personnalisé')
        ->and($type->fresh()->localizedName('es'))->toBe('Jabón en pastilla / barra limpiadora');
    expect($category->fresh()->localizedName('pt_BR'))->toBe('Outros');
    expect($otherCategory->fresh()->localizedName('pt_BR'))->toBe('Outros');
    expect($customType->fresh()->translations)->toBe(['fr' => ['name' => 'Type privé']]);
    expect($customAreaSlugType->fresh()->translations)->toBe(['fr' => ['name' => 'Type spécifique']]);
    $this->artisan('taxonomy:backfill-translations')->expectsOutputToContain('0 records updated')->assertSuccessful();
});
