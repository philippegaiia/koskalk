<?php

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientIdentifierScheme;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores typed ingredient identifiers and aliases with enum casts', function (): void {
    expect(Schema::hasTable('ingredient_identifiers'))->toBeTrue()
        ->and(Schema::hasTable('ingredient_aliases'))->toBeTrue();

    $ingredient = Ingredient::factory()->create();
    $identifier = $ingredient->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Cas,
        'value' => '19856-23-6',
        'normalized_value' => '19856-23-6',
        'is_primary' => true,
    ]);
    $alias = $ingredient->aliases()->create([
        'locale' => 'und',
        'name' => 'Sodium 4-oxovalerate',
        'normalized_name' => 'sodium 4-oxovalerate',
        'kind' => IngredientAliasKind::Common,
    ]);

    expect($identifier->fresh()->scheme)->toBe(IngredientIdentifierScheme::Cas)
        ->and($alias->fresh()->kind)->toBe(IngredientAliasKind::Common)
        ->and($ingredient->fresh()->identifiers)->toHaveCount(1)
        ->and($ingredient->fresh()->aliases)->toHaveCount(1);
});

it('prevents duplicate identifiers and aliases per ingredient while allowing ambiguous catalogue values', function (): void {
    $first = Ingredient::factory()->create();
    $second = Ingredient::factory()->create();

    $first->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Cas,
        'value' => '19856-23-6',
        'normalized_value' => '19856-23-6',
        'is_primary' => true,
    ]);
    $second->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Cas,
        'value' => '19856-23-6',
        'normalized_value' => '19856-23-6',
        'is_primary' => true,
    ]);

    expect(fn () => $first->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Cas,
        'value' => '19856-23-6',
        'normalized_value' => '19856-23-6',
        'is_primary' => false,
    ]))->toThrow(Exception::class);

    $first->aliases()->create([
        'locale' => 'und',
        'name' => 'Black cumin',
        'normalized_name' => 'black cumin',
        'kind' => IngredientAliasKind::Common,
    ]);
    $second->aliases()->create([
        'locale' => 'und',
        'name' => 'Black cumin',
        'normalized_name' => 'black cumin',
        'kind' => IngredientAliasKind::Common,
    ]);

    expect(IngredientAlias::query()->where('normalized_name', 'black cumin')->count())->toBe(2);
});

it('cascades identity rows when an ingredient is deleted', function (): void {
    $ingredient = Ingredient::factory()->create();

    $ingredient->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Ec,
        'value' => '243-378-4',
        'normalized_value' => '243-378-4',
        'is_primary' => true,
    ]);
    $ingredient->aliases()->create([
        'locale' => 'fr',
        'name' => 'Levulinate de sodium',
        'normalized_name' => 'levulinate de sodium',
        'kind' => IngredientAliasKind::Common,
    ]);

    $ingredient->delete();

    expect(IngredientIdentifier::query()->count())->toBe(0)
        ->and(IngredientAlias::query()->count())->toBe(0);
});
