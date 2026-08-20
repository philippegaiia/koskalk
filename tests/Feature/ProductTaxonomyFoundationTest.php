<?php

use App\Enums\IfraAmendmentStatus;
use App\Enums\IfraCategorySelectionMode;
use App\Enums\IfraCreationTrack;
use App\Enums\IfraStandardKind;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use App\Models\IfraCertificate;
use App\Models\IfraProductCategory;
use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\RecipeVersion;
use Database\Seeders\IfraAmendmentSeeder;
use Database\Seeders\IfraProductCategorySeeder;
use Database\Seeders\ProductFamilySeeder;
use Database\Seeders\ProductTaxonomySeeder;
use Database\Seeders\ProductTypeIfraCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores user taxonomy independently from calculation families', function (): void {
    expect(Schema::hasColumns('product_areas', ['name', 'slug', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('product_categories', ['product_area_id', 'name', 'slug', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('product_family_product_type', ['product_family_id', 'product_type_id']))->toBeTrue()
        ->and(Schema::hasColumn('product_family_product_type', 'id'))->toBeFalse()
        ->and(Schema::hasColumn('product_types', 'product_category_id'))->toBeTrue()
        ->and(Schema::hasIndex('product_family_product_type', ['product_type_id']))->toBeTrue()
        ->and(Schema::hasIndex('product_types', ['product_category_id']))->toBeTrue()
        ->and(Schema::hasIndex('recipes', ['product_type_id']))->toBeTrue();
});

it('stores amendment milestones without putting a creation track on formulas', function (): void {
    expect(Schema::hasColumns('ifra_amendments', ['code', 'status', 'notification_date', 'source_url']))->toBeTrue()
        ->and(Schema::hasColumns('ifra_amendment_milestones', ['ifra_amendment_id', 'standard_kind', 'creation_track', 'effective_on']))->toBeTrue()
        ->and(Schema::hasColumns('product_type_ifra_categories', ['product_type_id', 'ifra_amendment_id', 'ifra_product_category_id', 'is_default', 'guidance', 'source_url', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('recipe_versions', ['ifra_amendment_id', 'product_type_ifra_category_id', 'ifra_category_selection_mode']))->toBeTrue()
        ->and(Schema::hasIndex('recipe_versions', ['ifra_amendment_id']))->toBeTrue()
        ->and(Schema::hasIndex('recipe_versions', ['product_type_ifra_category_id']))->toBeTrue()
        ->and(Schema::hasIndex('ifra_certificates', ['ifra_amendment_id']))->toBeTrue()
        ->and(Schema::hasColumn('recipe_versions', 'ifra_creation_track'))->toBeFalse();
});

it('conservatively backfills legacy IFRA resolution data', function (): void {
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $category = IfraProductCategory::factory()->create();
    $legacyVersion = RecipeVersion::factory()->create([
        'ifra_product_category_id' => $category->id,
    ]);
    $unclassifiedVersion = RecipeVersion::factory()->create([
        'ifra_product_category_id' => null,
    ]);
    $certificate = IfraCertificate::factory()->create([
        'ifra_amendment' => ' 51 ',
        'ifra_amendment_id' => null,
        'source_amendment_label' => ' 51 ',
    ]);
    $migration = require database_path('migrations/2026_08_20_094700_add_ifra_resolution_to_recipe_versions_and_certificates.php');

    $migration->down();
    $migration->up();

    expect(DB::table('recipe_versions')->where('id', $legacyVersion->id)->value('ifra_category_selection_mode'))
        ->toBe(IfraCategorySelectionMode::Legacy->value)
        ->and(DB::table('recipe_versions')->where('id', $unclassifiedVersion->id)->value('ifra_category_selection_mode'))
        ->toBe(IfraCategorySelectionMode::Automatic->value)
        ->and(DB::table('ifra_certificates')->where('id', $certificate->id)->value('ifra_amendment_id'))
        ->toBe($amendment->id)
        ->and(DB::table('ifra_certificates')->where('id', $certificate->id)->value('source_amendment_label'))
        ->toBe(' 51 ');
});

it('links exact legacy certificate labels when the amendment catalog is seeded', function (): void {
    $matchingCertificate = IfraCertificate::factory()->create([
        'ifra_amendment' => ' 51 ',
        'ifra_amendment_id' => null,
        'source_amendment_label' => null,
    ]);
    $unknownCertificate = IfraCertificate::factory()->create([
        'ifra_amendment' => '51st supplier format',
        'ifra_amendment_id' => null,
        'source_amendment_label' => null,
    ]);

    $this->seed(IfraAmendmentSeeder::class);
    $amendment = IfraAmendment::query()->where('code', '51')->firstOrFail();

    expect($matchingCertificate->fresh()->ifra_amendment_id)->toBe($amendment->id)
        ->and($matchingCertificate->fresh()->source_amendment_label)->toBe(' 51 ')
        ->and($unknownCertificate->fresh()->ifra_amendment_id)->toBeNull()
        ->and($unknownCertificate->fresh()->source_amendment_label)->toBe('51st supplier format');
});

it('keeps retired family-level IFRA classification out of runtime code', function (): void {
    expect(method_exists(ProductFamily::class, 'ifraCategoryMappings'))->toBeFalse()
        ->and(method_exists(ProductFamily::class, 'ifraProductCategories'))->toBeFalse()
        ->and(method_exists(ProductType::class, 'productFamily'))->toBeFalse()
        ->and(method_exists(ProductType::class, 'defaultIfraProductCategory'))->toBeFalse()
        ->and(method_exists(IfraProductCategory::class, 'productFamilyMappings'))->toBeFalse()
        ->and(method_exists(IfraProductCategory::class, 'productFamilies'))->toBeFalse();

    $runtimeFiles = collect([
        ...File::allFiles(app_path('Services')),
        ...File::allFiles(app_path('Livewire')),
    ]);

    expect($runtimeFiles->contains(
        fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'ProductFamilyIfraCategory'),
    ))->toBeFalse()
        ->and($runtimeFiles->contains(
            fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'product_family_ifra_categories'),
        ))->toBeFalse()
        ->and($runtimeFiles->contains(
            fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'default_ifra_product_category_id'),
        ))->toBeFalse();
});

it('defines the amendment classification enums as stable string values', function (): void {
    expect(IfraAmendmentStatus::Consultation->value)->toBe('consultation')
        ->and(IfraAmendmentStatus::Notified->value)->toBe('notified')
        ->and(IfraAmendmentStatus::Superseded->value)->toBe('superseded')
        ->and(IfraCategorySelectionMode::Automatic->value)->toBe('automatic')
        ->and(IfraCategorySelectionMode::Manual->value)->toBe('manual')
        ->and(IfraCategorySelectionMode::Legacy->value)->toBe('legacy')
        ->and(IfraCreationTrack::New->value)->toBe('new')
        ->and(IfraCreationTrack::Existing->value)->toBe('existing')
        ->and(IfraStandardKind::Prohibition->value)->toBe('prohibition')
        ->and(IfraStandardKind::RestrictionSpecification->value)->toBe('restriction_specification');
});

it('allows a product type without a legacy calculation family', function (): void {
    DB::table('product_types')->insert([
        'product_family_id' => null,
        'name' => 'Unassigned legacy type',
        'slug' => 'unassigned-legacy-type',
    ]);

    expect(DB::table('product_types')->where('slug', 'unassigned-legacy-type')->exists())->toBeTrue();
});

it('enforces globally unique active product type slugs while preserving inactive history', function (): void {
    $firstFamilyId = productFamilyId('first-family');
    $secondFamilyId = productFamilyId('second-family');

    productTypeId($firstFamilyId, 'shared-active-slug');

    expect(fn () => productTypeId($secondFamilyId, 'shared-active-slug'))
        ->toThrow(QueryException::class);

    DB::table('product_types')->insert([
        'product_family_id' => $secondFamilyId,
        'name' => 'Historical type',
        'slug' => 'shared-active-slug',
        'is_active' => false,
    ]);

    expect(DB::table('product_types')->where('slug', 'shared-active-slug')->count())->toBe(2);
});

it('uses a conventional composite-key pivot for calculation family compatibility', function (): void {
    $soapFamilyId = productFamilyId('soap');
    $cosmeticFamilyId = productFamilyId('cosmetic');
    $productTypeId = productTypeId($soapFamilyId, 'cleansing-bar');

    DB::table('product_family_product_type')->insert([
        ['product_family_id' => $soapFamilyId, 'product_type_id' => $productTypeId],
        ['product_family_id' => $cosmeticFamilyId, 'product_type_id' => $productTypeId],
    ]);

    expect(DB::table('product_family_product_type')->where('product_type_id', $productTypeId)->count())->toBe(2)
        ->and(fn () => DB::table('product_family_product_type')->insert([
            'product_family_id' => $soapFamilyId,
            'product_type_id' => $productTypeId,
        ]))->toThrow(QueryException::class);
});

it('allows several IFRA candidates but only one default per type and amendment', function (): void {
    $familyId = productFamilyId('cosmetic');
    $productTypeId = productTypeId($familyId, 'body-mist');
    $amendmentId = DB::table('ifra_amendments')->insertGetId([
        'code' => '51',
        'status' => 'notified',
    ]);

    $categoryIds = collect(['2', '4', '5A', '5B'])
        ->map(fn (string $code): int => DB::table('ifra_product_categories')->insertGetId([
            'code' => $code,
            'name' => "Category {$code}",
        ]));

    foreach ($categoryIds->take(2) as $categoryId) {
        DB::table('product_type_ifra_categories')->insert([
            'product_type_id' => $productTypeId,
            'ifra_amendment_id' => $amendmentId,
            'ifra_product_category_id' => $categoryId,
            'is_default' => false,
        ]);
    }

    DB::table('product_type_ifra_categories')->insert([
        'product_type_id' => $productTypeId,
        'ifra_amendment_id' => $amendmentId,
        'ifra_product_category_id' => $categoryIds[2],
        'is_default' => true,
    ]);

    expect(DB::table('product_type_ifra_categories')->where('is_default', false)->count())->toBe(2)
        ->and(fn () => DB::table('product_type_ifra_categories')->insert([
            'product_type_id' => $productTypeId,
            'ifra_amendment_id' => $amendmentId,
            'ifra_product_category_id' => $categoryIds[3],
            'is_default' => true,
        ]))->toThrow(QueryException::class);
});

it('navigates taxonomy, compatibility, amendment, and formula relationships', function (): void {
    $area = ProductArea::factory()->create();
    $category = ProductCategory::factory()->create(['product_area_id' => $area->id]);
    $soapFamily = ProductFamily::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create();
    $productType = ProductType::factory()->create([
        'product_category_id' => $category->id,
        'product_family_id' => $soapFamily->id,
    ]);
    $productType->productFamilies()->syncWithoutDetaching([$cosmeticFamily->id]);

    $amendment = IfraAmendment::factory()->create();
    $newCreationMilestone = IfraAmendmentMilestone::factory()->create([
        'ifra_amendment_id' => $amendment->id,
        'standard_kind' => IfraStandardKind::Prohibition,
        'creation_track' => IfraCreationTrack::New,
    ]);
    $existingCreationMilestone = IfraAmendmentMilestone::factory()->create([
        'ifra_amendment_id' => $amendment->id,
        'standard_kind' => IfraStandardKind::Prohibition,
        'creation_track' => IfraCreationTrack::Existing,
    ]);
    $ifraCategory = IfraProductCategory::factory()->create();
    $mapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $ifraCategory->id,
        'is_default' => true,
    ]);
    $certificate = IfraCertificate::factory()->create(['ifra_amendment_id' => $amendment->id]);
    $recipeVersion = RecipeVersion::factory()->create([
        'ifra_amendment_id' => $amendment->id,
        'product_type_ifra_category_id' => $mapping->id,
        'ifra_category_selection_mode' => IfraCategorySelectionMode::Automatic,
    ]);

    expect($area->productCategories()->firstOrFail()->is($category))->toBeTrue()
        ->and($category->productArea->is($area))->toBeTrue()
        ->and($category->productTypes()->firstOrFail()->is($productType))->toBeTrue()
        ->and($productType->productCategory->is($category))->toBeTrue()
        ->and($productType->productFamilies()->pluck('product_families.id')->sort()->values()->all())
        ->toBe(collect([$soapFamily->id, $cosmeticFamily->id])->sort()->values()->all())
        ->and($soapFamily->productTypes()->firstOrFail()->is($productType))->toBeTrue()
        ->and($amendment->milestones()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$newCreationMilestone->id, $existingCreationMilestone->id])->sort()->values()->all())
        ->and($amendment->productTypeMappings()->firstOrFail()->is($mapping))->toBeTrue()
        ->and($mapping->productType->is($productType))->toBeTrue()
        ->and($mapping->ifraAmendment->is($amendment))->toBeTrue()
        ->and($mapping->ifraProductCategory->is($ifraCategory))->toBeTrue()
        ->and($ifraCategory->productTypeMappings()->firstOrFail()->is($mapping))->toBeTrue()
        ->and($newCreationMilestone->ifraAmendment->is($amendment))->toBeTrue()
        ->and($certificate->ifraAmendment->is($amendment))->toBeTrue()
        ->and($recipeVersion->ifraAmendment->is($amendment))->toBeTrue()
        ->and($recipeVersion->productTypeIfraCategory->is($mapping))->toBeTrue()
        ->and($recipeVersion->ifra_category_selection_mode)->toBe(IfraCategorySelectionMode::Automatic);
});

it('seeds the exact canonical finished-product catalog', function (): void {
    seedProductTaxonomy();

    expect(ProductArea::query()->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['personal-care', 'home-household'])
        ->and(ProductType::query()->where('is_active', true)->count())->toBe(45)
        ->and(ProductType::query()->where('slug', 'bar-soap-cleansing-bar')->firstOrFail()
            ->productFamilies()->pluck('product_families.slug')->sort()->values()->all())
        ->toBe(['cosmetic', 'soap'])
        ->and(ProductType::query()->where('slug', 'candle-wax-melt')->firstOrFail()
            ->productFamilies()->pluck('product_families.slug')->all())
        ->toBe(['cosmetic']);
});

it('seeds Amendment 51 milestones and load-bearing product mappings', function (): void {
    seedProductTaxonomy();

    $amendment = IfraAmendment::query()->where('code', '51')->firstOrFail();
    $mappingCodes = function (string $slug) use ($amendment): array {
        return ProductType::query()
            ->where('slug', $slug)
            ->firstOrFail()
            ->ifraCategoryMappings()
            ->where('ifra_amendment_id', $amendment->id)
            ->with('ifraProductCategory:id,code')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProductTypeIfraCategory $mapping): array => [
                'code' => $mapping->ifraProductCategory->code,
                'is_default' => $mapping->is_default,
                'guidance' => $mapping->guidance,
            ])
            ->all();
    };

    expect($amendment->status)->toBe(IfraAmendmentStatus::Notified)
        ->and($amendment->notification_date->toDateString())->toBe('2023-06-30')
        ->and(IfraAmendment::query()->where('code', '52')->exists())->toBeFalse()
        ->and($amendment->milestones()->orderBy('effective_on')->get()
            ->map(fn (IfraAmendmentMilestone $milestone): string => implode('|', [
                $milestone->standard_kind->value,
                $milestone->creation_track->value,
                $milestone->effective_on->toDateString(),
            ]))->all())
        ->toBe([
            'prohibition|new|2023-08-30',
            'restriction_specification|new|2024-03-30',
            'prohibition|existing|2024-07-30',
            'restriction_specification|existing|2025-10-30',
        ])
        ->and($mappingCodes('shampoo')[0]['code'])->toBe('9')
        ->and($mappingCodes('rinse-off-conditioner')[0]['code'])->toBe('9')
        ->and($mappingCodes('rinse-off-hair-chemical-treatment')[0]['code'])->toBe('7A')
        ->and(collect($mappingCodes('body-mist-spray'))->pluck('code')->all())->toBe(['2', '4'])
        ->and($mappingCodes('body-mist-spray')[0]['is_default'])->toBeTrue()
        ->and($mappingCodes('candle-wax-melt')[0]['code'])->toBe('12')
        ->and($mappingCodes('skin-contact-massage-candle')[0]['code'])->toBe('5A')
        ->and($mappingCodes('hand-dishwashing-product')[0]['code'])->toBe('10A')
        ->and($mappingCodes('hand-wash-laundry-product')[0]['code'])->toBe('10A')
        ->and($mappingCodes('automatic-dishwasher-product')[0]['code'])->toBe('12');
});

it('keeps exactly one IFRA default per mapped type and preserves interpretive guidance', function (): void {
    seedProductTaxonomy();

    $amendment = IfraAmendment::query()->where('code', '51')->firstOrFail();
    $mappingGroups = ProductTypeIfraCategory::query()
        ->where('ifra_amendment_id', $amendment->id)
        ->get()
        ->groupBy('product_type_id');

    expect($mappingGroups)->toHaveCount(43);

    $mappingGroups->each(function ($mappings): void {
        expect($mappings->where('is_default', true))->toHaveCount(1);
    });

    $bodyMistAlternative = ProductTypeIfraCategory::query()
        ->whereBelongsTo(ProductType::query()->where('slug', 'body-mist-spray')->firstOrFail())
        ->whereBelongsTo(IfraProductCategory::query()->where('code', '4')->firstOrFail())
        ->firstOrFail();
    $massageCandle = ProductTypeIfraCategory::query()
        ->whereBelongsTo(ProductType::query()->where('slug', 'skin-contact-massage-candle')->firstOrFail())
        ->firstOrFail();
    $aftershaveBalm = ProductTypeIfraCategory::query()
        ->whereBelongsTo(ProductType::query()->where('slug', 'aftershave-cream-balm')->firstOrFail())
        ->firstOrFail();

    expect(ProductType::query()->whereIn('slug', ['other-cosmetics', 'other-home-product'])
        ->whereHas('ifraCategoryMappings', fn ($query) => $query->where('is_active', true))->exists())->toBeFalse()
        ->and($bodyMistAlternative->guidance)
        ->toBe('Use Category 4 only when the product is clearly labelled not for axillary use and not as a deodorant; otherwise foreseeable axillary use keeps it in Category 2.')
        ->and($massageCandle->guidance)
        ->toBe('Use this mapping only when the melted product is intended to be applied to the body as a leave-on massage or body oil. An ordinary burned candle or wax melt is Category 12.')
        ->and($aftershaveBalm->guidance)
        ->toBe('Use this mapping when the product is presented and used as a leave-on face moisturizer. Aftershaves other than creams and balms are Category 4.');
});

it('reuses the historical lip type, deactivates superseded starters, and seeds idempotently', function (): void {
    $this->seed(ProductFamilySeeder::class);
    $cosmeticFamily = ProductFamily::query()->where('slug', 'cosmetic')->firstOrFail();
    $historicalLipType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => null,
        'slug' => 'lip-product',
        'is_active' => false,
    ]);
    $supersededType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => null,
        'slug' => 'cream-lotion',
        'is_active' => true,
    ]);

    seedProductTaxonomy();
    $firstCounts = [
        ProductArea::query()->count(),
        ProductCategory::query()->count(),
        ProductType::query()->count(),
        ProductTypeIfraCategory::query()->count(),
        IfraAmendmentMilestone::query()->count(),
    ];

    seedProductTaxonomy();

    expect(ProductType::query()->where('slug', 'lip-product')->where('is_active', true)->value('id'))
        ->toBe($historicalLipType->id)
        ->and($supersededType->fresh()->is_active)->toBeFalse()
        ->and([
            ProductArea::query()->count(),
            ProductCategory::query()->count(),
            ProductType::query()->count(),
            ProductTypeIfraCategory::query()->count(),
            IfraAmendmentMilestone::query()->count(),
        ])->toBe($firstCounts);
});

function productFamilyId(string $slug): int
{
    return DB::table('product_families')->insertGetId([
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
        'calculation_basis' => 'total_formula',
    ]);
}

function productTypeId(int $productFamilyId, string $slug): int
{
    return DB::table('product_types')->insertGetId([
        'product_family_id' => $productFamilyId,
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
    ]);
}

function seedProductTaxonomy(): void
{
    test()->seed([
        ProductFamilySeeder::class,
        IfraProductCategorySeeder::class,
        IfraAmendmentSeeder::class,
        ProductTaxonomySeeder::class,
        ProductTypeIfraCategorySeeder::class,
    ]);
}
