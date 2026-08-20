<?php

use App\Enums\IfraAmendmentStatus;
use App\Enums\IfraCategorySelectionMode;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\User;
use App\Services\ProductTypeIfraOptionsBuilder;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the latest notified amendment that has mappings for the Product Type', function (): void {
    $category9 = IfraProductCategory::factory()->create(['code' => '9']);
    $productType = ProductType::factory()->create();
    $latestNotifiedAmendment = IfraAmendment::factory()->create([
        'code' => '51-revision',
        'status' => IfraAmendmentStatus::Notified,
        'notification_date' => '2024-11-30',
    ]);
    $amendment51 = IfraAmendment::factory()->create([
        'code' => '51',
        'status' => IfraAmendmentStatus::Notified,
        'notification_date' => '2023-06-30',
    ]);
    IfraAmendment::factory()->create([
        'code' => '53',
        'status' => IfraAmendmentStatus::Consultation,
        'notification_date' => '2025-01-01',
    ]);
    $mapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment51->id,
        'ifra_product_category_id' => $category9->id,
        'is_default' => true,
    ]);
    IfraAmendmentMilestone::factory()->create([
        'ifra_amendment_id' => $amendment51->id,
        'effective_on' => '2024-03-30',
    ]);

    $options = app(ProductTypeIfraOptionsBuilder::class)->build($productType);

    expect($options['amendment'])->toMatchArray([
        'id' => $amendment51->id,
        'code' => '51',
        'status' => 'notified',
    ])->and($options['default_category_id'])->toBe($category9->id)
        ->and($options['default_mapping_id'])->toBe($mapping->id)
        ->and($options['milestones'])->toHaveCount(1)
        ->and($options['milestones'][0]['effective_on'])->toBe('2024-03-30');

    $unmappedOptions = app(ProductTypeIfraOptionsBuilder::class)->build(ProductType::factory()->create());

    expect($unmappedOptions['amendment']['id'])->toBe($latestNotifiedAmendment->id)
        ->and($unmappedOptions['options'])->toBe([])
        ->and($unmappedOptions['default_category_id'])->toBeNull();
});

it('orders mapped candidates and exposes guidance without hiding the complete category catalog', function (): void {
    $category2 = IfraProductCategory::factory()->create(['code' => '2', 'name' => 'Body sprays']);
    $category4 = IfraProductCategory::factory()->create(['code' => '4', 'name' => 'Fine fragrance']);
    IfraProductCategory::factory()->create(['code' => '10A', 'name' => 'Household contact']);
    $inactiveSelected = IfraProductCategory::factory()->create(['code' => '7B', 'is_active' => false]);
    $productType = ProductType::factory()->create();
    $amendment = IfraAmendment::factory()->create([
        'code' => '51',
        'status' => IfraAmendmentStatus::Notified,
        'notification_date' => '2023-06-30',
    ]);
    $defaultMapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $category2->id,
        'is_default' => true,
        'sort_order' => 10,
    ]);
    $alternativeMapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $category4->id,
        'is_default' => false,
        'guidance' => 'Only when axillary use is excluded.',
        'sort_order' => 20,
    ]);

    $options = app(ProductTypeIfraOptionsBuilder::class)->build($productType, $inactiveSelected);

    expect(collect($options['options'])->pluck('mapping_id')->all())
        ->toBe([$defaultMapping->id, $alternativeMapping->id])
        ->and($options['options'][0])->toMatchArray([
            'id' => $category2->id,
            'is_default' => true,
        ])->and($options['options'][1])->toMatchArray([
            'id' => $category4->id,
            'guidance' => 'Only when axillary use is excluded.',
            'is_default' => false,
        ])->and(collect($options['all_categories'])->pluck('code')->all())
        ->toBe(['2', '4', '7B', '10A']);
});

it('returns every active category and no suggestion for an unmapped Product Type', function (): void {
    IfraProductCategory::factory()->create(['code' => '9']);
    IfraProductCategory::factory()->create(['code' => '10A']);
    IfraProductCategory::factory()->create(['code' => '12']);
    IfraProductCategory::factory()->create(['code' => '4', 'is_active' => false]);
    IfraAmendment::factory()->create([
        'code' => '51',
        'status' => IfraAmendmentStatus::Notified,
        'notification_date' => '2023-06-30',
    ]);

    $options = app(ProductTypeIfraOptionsBuilder::class)->build(ProductType::factory()->create());

    expect($options['default_category_id'])->toBeNull()
        ->and($options['default_mapping_id'])->toBeNull()
        ->and($options['options'])->toBe([])
        ->and(collect($options['all_categories'])->pluck('code')->all())->toBe(['9', '10A', '12']);
});

it('persists automatic and optional manual IFRA selections without requiring fragrance', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $family = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $ingredient = Ingredient::factory()->create();
    $defaultCategory = IfraProductCategory::factory()->create(['code' => '9']);
    $alternativeCategory = IfraProductCategory::factory()->create(['code' => '7B']);
    $outsideCategory = IfraProductCategory::factory()->create(['code' => '12']);
    $amendment = IfraAmendment::factory()->create([
        'code' => '51',
        'status' => IfraAmendmentStatus::Notified,
        'notification_date' => '2023-06-30',
    ]);
    $defaultMapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $defaultCategory->id,
        'is_default' => true,
        'sort_order' => 10,
    ]);
    $alternativeMapping = ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $alternativeCategory->id,
        'is_default' => false,
        'sort_order' => 20,
    ]);
    $service = app(RecipeWorkbenchService::class);

    $automatic = $service->save($user, $family, ifraCosmeticPayload($ingredient, $productType));

    expect($automatic->ifra_category_selection_mode)->toBe(IfraCategorySelectionMode::Automatic)
        ->and($automatic->ifra_amendment_id)->toBe($amendment->id)
        ->and($automatic->product_type_ifra_category_id)->toBe($defaultMapping->id)
        ->and($automatic->ifra_product_category_id)->toBe($defaultCategory->id);

    $automaticWithStaleCategory = $service->save($user, $family, ifraCosmeticPayload(
        $ingredient,
        $productType,
        ['ifra_product_category_id' => $outsideCategory->id],
    ), $automatic->recipe);

    expect($automaticWithStaleCategory->product_type_ifra_category_id)->toBe($defaultMapping->id)
        ->and($automaticWithStaleCategory->ifra_product_category_id)->toBe($defaultCategory->id);

    $manualNull = $service->save($user, $family, ifraCosmeticPayload(
        $ingredient,
        $productType,
        ['ifra_category_selection_mode' => 'manual'],
    ), $automatic->recipe);

    expect($manualNull->ifra_category_selection_mode)->toBe(IfraCategorySelectionMode::Manual)
        ->and($manualNull->ifra_amendment_id)->toBe($amendment->id)
        ->and($manualNull->product_type_ifra_category_id)->toBeNull()
        ->and($manualNull->ifra_product_category_id)->toBeNull();

    $manualMapped = $service->save($user, $family, ifraCosmeticPayload(
        $ingredient,
        $productType,
        [
            'ifra_category_selection_mode' => 'manual',
            'ifra_product_category_id' => $alternativeCategory->id,
        ],
    ), $automatic->recipe);

    expect($manualMapped->product_type_ifra_category_id)->toBe($alternativeMapping->id)
        ->and($manualMapped->ifra_product_category_id)->toBe($alternativeCategory->id);

    $manualOutside = $service->save($user, $family, ifraCosmeticPayload(
        $ingredient,
        $productType,
        [
            'ifra_category_selection_mode' => 'manual',
            'ifra_product_category_id' => $outsideCategory->id,
        ],
    ), $automatic->recipe);

    expect($manualOutside->product_type_ifra_category_id)->toBeNull()
        ->and($manualOutside->ifra_product_category_id)->toBe($outsideCategory->id);

    $roundTripPayload = $service->currentVersionPayloadUsingCatalog($manualOutside->recipe, []);

    expect($roundTripPayload['ifraCategorySelectionMode'])->toBe('manual')
        ->and($roundTripPayload['ifraAmendmentId'])->toBe($amendment->id)
        ->and($roundTripPayload['selectedIfraProductCategoryId'])->toBe($outsideCategory->id);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ifraCosmeticPayload(Ingredient $ingredient, ProductType $productType, array $overrides = []): array
{
    return [
        'name' => 'Optional IFRA formula',
        'product_type_id' => $productType->id,
        'oil_weight' => 100,
        'oil_unit' => 'g',
        'editing_mode' => 'percentage',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'phases' => [['key' => 'phase_a', 'name' => 'Phase A']],
        'phase_items' => [
            'phase_a' => [[
                'ingredient_id' => $ingredient->id,
                'percentage' => 100,
                'weight' => 100,
                'note' => null,
            ]],
        ],
        ...$overrides,
    ];
}
