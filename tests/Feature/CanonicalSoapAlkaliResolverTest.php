<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\CanonicalSoapAlkaliResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('resolves naoh and koh to the active platform canonical records', function () {
    $canonicalNaoh = makeCanonicalAlkali('CH1', IngredientSubcategory::SodiumHydroxide, 'Sodium hydroxide');
    $canonicalKoh = makeCanonicalAlkali('CH3', IngredientSubcategory::PotassiumHydroxide, 'Potassium hydroxide');

    $resolver = app(CanonicalSoapAlkaliResolver::class);

    expect($resolver->resolve('naoh')->is($canonicalNaoh))->toBeTrue()
        ->and($resolver->resolve('koh')->is($canonicalKoh))->toBeTrue();
});

it('ignores accessible workspace and user duplicates with the same alkali taxonomy', function () {
    $user = User::factory()->create();
    $canonicalNaoh = makeCanonicalAlkali('CH1', IngredientSubcategory::SodiumHydroxide, 'Sodium hydroxide');
    $canonicalKoh = makeCanonicalAlkali('CH3', IngredientSubcategory::PotassiumHydroxide, 'Potassium hydroxide');

    makeAlkaliDuplicate($user, IngredientSubcategory::SodiumHydroxide, 'My Caustic Soda');
    makeAlkaliDuplicate($user, IngredientSubcategory::PotassiumHydroxide, 'My Potash');

    $resolver = app(CanonicalSoapAlkaliResolver::class);

    expect($resolver->resolve('naoh')->is($canonicalNaoh))->toBeTrue()
        ->and($resolver->resolve('koh')->is($canonicalKoh))->toBeTrue();
});

it('resolves many lye types in one query', function () {
    $canonicalNaoh = makeCanonicalAlkali('CH1', IngredientSubcategory::SodiumHydroxide, 'Sodium hydroxide');
    $canonicalKoh = makeCanonicalAlkali('CH3', IngredientSubcategory::PotassiumHydroxide, 'Potassium hydroxide');

    $resolved = app(CanonicalSoapAlkaliResolver::class)->resolveMany(['naoh', 'koh']);

    expect($resolved->keys()->all())->toBe(['naoh', 'koh'])
        ->and($resolved->get('naoh')->is($canonicalNaoh))->toBeTrue()
        ->and($resolved->get('koh')->is($canonicalKoh))->toBeTrue();
});

it('throws a validation exception when the canonical record is missing even if a duplicate exists', function () {
    $user = User::factory()->create();
    makeAlkaliDuplicate($user, IngredientSubcategory::SodiumHydroxide, 'My Caustic Soda');

    app(CanonicalSoapAlkaliResolver::class)->resolve('naoh');
})->throws(ValidationException::class);

it('throws a validation exception when the canonical record is inactive', function () {
    makeCanonicalAlkali('CH3', IngredientSubcategory::PotassiumHydroxide, 'Potassium hydroxide', isActive: false);

    app(CanonicalSoapAlkaliResolver::class)->resolve('koh');
})->throws(ValidationException::class);

it('rejects an unsupported lye type without querying', function () {
    app(CanonicalSoapAlkaliResolver::class)->resolve('ammonia');
})->throws(InvalidArgumentException::class);

function makeCanonicalAlkali(string $catalogKey, IngredientSubcategory $subcategory, string $displayName, bool $isActive = true): Ingredient
{
    return Ingredient::factory()->create([
        'catalog_key' => $catalogKey,
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => $subcategory,
        'display_name' => $displayName,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => $isActive,
    ]);
}

function makeAlkaliDuplicate(User $user, IngredientSubcategory $subcategory, string $displayName): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => $subcategory,
        'display_name' => $displayName,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);
}
