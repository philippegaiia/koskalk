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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
