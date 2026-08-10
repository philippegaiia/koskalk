<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;

it('exposes the canonical ingredient taxonomy', function () {
    expect(IngredientCategory::cases())->toHaveCount(22)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::Silicones)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::FunctionalPolymers)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::Other)
        ->and(method_exists(IngredientCategory::class, 'canonicalCases'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'canonical'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'isLegacy'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'valuesForCanonical'))->toBeFalse();
});

it('does not retain legacy values after the migration bridge', function () {
    expect(IngredientCategory::tryFrom('carrier_oil'))->toBeNull()
        ->and(IngredientCategory::tryFrom('essential_oil'))->toBeNull()
        ->and(IngredientCategory::tryFrom('additive'))->toBeNull();
});

it('maps every subcategory to exactly one canonical category', function () {
    $subcategories = IngredientSubcategory::cases();

    expect($subcategories)->not->toBeEmpty();

    foreach ($subcategories as $subcategory) {
        expect(IngredientCategory::cases())->toContain($subcategory->category());
    }
});

it('keeps conditioning as a functional polymer facet while silicones remain a material family', function () {
    expect(IngredientSubcategory::ConditioningPolymers->category())
        ->toBe(IngredientCategory::FunctionalPolymers)
        ->and(IngredientSubcategory::VolatileSilicones->category())
        ->toBe(IngredientCategory::Silicones);
});

it('does not offer a subcategory for other', function () {
    expect(IngredientSubcategory::forCategory(IngredientCategory::Other))->toBe([]);
});
