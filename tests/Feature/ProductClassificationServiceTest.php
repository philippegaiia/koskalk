<?php

use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\ProductClassificationService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('requires a compatible Product Type for a new Product', function (): void {
    $family = ProductFamily::factory()->create();
    $compatibleType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $incompatibleType = ProductType::factory()->create();
    $service = app(ProductClassificationService::class);

    expect(fn () => $service->resolveForSave($family, null, null))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->resolveForSave($family, $incompatibleType->id, null))
        ->toThrow(ValidationException::class)
        ->and($service->resolveForSave($family, $compatibleType->id, null)->is($compatibleType))
        ->toBeTrue();
});

it('allows a dual-engine cleansing type through both calculation families', function (): void {
    $soapFamily = ProductFamily::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create();
    $cleansingType = ProductType::factory()->create(['product_family_id' => $soapFamily->id]);
    $cleansingType->productFamilies()->syncWithoutDetaching([$cosmeticFamily->id]);
    $service = app(ProductClassificationService::class);

    expect($service->resolveForSave($soapFamily, $cleansingType->id, null)->is($cleansingType))->toBeTrue()
        ->and($service->resolveForSave($cosmeticFamily, $cleansingType->id, null)->is($cleansingType))->toBeTrue();
});

it('preserves a genuinely unclassified legacy Product without guessing', function (): void {
    $family = ProductFamily::factory()->create();
    $legacyProduct = Recipe::factory()->create([
        'product_family_id' => $family->id,
        'product_type_id' => null,
    ]);

    expect(app(ProductClassificationService::class)->resolveForSave($family, null, $legacyProduct))->toBeNull();
});

it('rejects clearing a stored type or changing the calculation family', function (): void {
    $family = ProductFamily::factory()->create();
    $otherFamily = ProductFamily::factory()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $product = Recipe::factory()->create([
        'product_family_id' => $family->id,
        'product_type_id' => $productType->id,
    ]);
    $service = app(ProductClassificationService::class);

    expect(fn () => $service->resolveForSave($family, null, $product))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->resolveForSave($otherFamily, $productType->id, $product))
        ->toThrow(ValidationException::class);
});

it('allows changing type before the first Saved Formula and then locks it', function (): void {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create();
    $firstType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $secondType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $product = Recipe::factory()->create([
        'product_family_id' => $family->id,
        'product_type_id' => $firstType->id,
        'owner_id' => $user->id,
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $product->id,
        'owner_id' => $user->id,
        'is_current' => true,
    ]);
    $service = app(ProductClassificationService::class);

    expect($service->resolveForSave($family, $secondType->id, $product)->is($secondType))->toBeTrue();

    RecipeVersion::factory()->create([
        'recipe_id' => $product->id,
        'owner_id' => $user->id,
        'version_number' => 2,
        'is_current' => false,
    ]);

    expect(RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $product->id)
        ->where('is_current', false)
        ->exists())->toBeTrue();

    expect(fn () => $service->resolveForSave($family, $secondType->id, $product))
        ->toThrow(ValidationException::class)
        ->and($service->resolveForSave($family, $firstType->id, $product)->is($firstType))->toBeTrue();
});

it('round-trips the Product existing inactive type', function (): void {
    $family = ProductFamily::factory()->create();
    $inactiveType = ProductType::factory()->create([
        'product_family_id' => $family->id,
        'is_active' => false,
    ]);
    $product = Recipe::factory()->create([
        'product_family_id' => $family->id,
        'product_type_id' => $inactiveType->id,
    ]);

    expect(app(ProductClassificationService::class)->resolveForSave($family, $inactiveType->id, $product)->is($inactiveType))
        ->toBeTrue();
});

it('does not assign an inactive type to a new Product', function (): void {
    $family = ProductFamily::factory()->create();
    $inactiveType = ProductType::factory()->create([
        'product_family_id' => $family->id,
        'is_active' => false,
    ]);

    expect(fn () => app(ProductClassificationService::class)->resolveForSave($family, $inactiveType->id, null))
        ->toThrow(ValidationException::class);
});

it('persists the resolved type through draft and direct publish creation and locks it after publish', function (): void {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $firstType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $secondType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $ingredient = Ingredient::factory()->create();
    $service = app(RecipeWorkbenchService::class);

    $draft = $service->save($user, $family, classificationCosmeticPayload($ingredient, $firstType));
    $product = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);

    expect($product->product_type_id)->toBe($firstType->id);

    $service->save($user, $family, classificationCosmeticPayload($ingredient, $secondType), $product);
    expect($product->fresh()->product_type_id)->toBe($secondType->id);

    $service->publish($user, $family, classificationCosmeticPayload($ingredient, $secondType), $product);

    expect(fn () => $service->save(
        $user,
        $family,
        classificationCosmeticPayload($ingredient, $firstType),
        $product,
    ))->toThrow(ValidationException::class)
        ->and($product->fresh()->product_type_id)->toBe($secondType->id);

    $directPublished = $service->publish(
        $user,
        $family,
        classificationCosmeticPayload($ingredient, $firstType),
    );

    expect(Recipe::withoutGlobalScopes()->findOrFail($directPublished->recipe_id)->product_type_id)
        ->toBe($firstType->id);
});

/**
 * @return array<string, mixed>
 */
function classificationCosmeticPayload(Ingredient $ingredient, ProductType $productType): array
{
    return [
        'name' => 'Classification formula',
        'product_type_id' => $productType->id,
        'oil_weight' => 100,
        'oil_unit' => 'g',
        'editing_mode' => 'percentage',
        'exposure_mode' => 'leave_on',
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
    ];
}
