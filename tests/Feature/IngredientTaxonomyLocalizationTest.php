<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has translated labels and descriptions for every taxonomy term', function () {
    $english = require lang_path('en/ingredients.php');

    foreach (IngredientCategory::cases() as $category) {
        expect(data_get($english, "categories.{$category->value}.label"))->toBeString()->not->toBe('')
            ->and(data_get($english, "categories.{$category->value}.description"))->toBeString()->not->toBe('');
    }

    foreach (IngredientSubcategory::cases() as $subcategory) {
        expect(data_get($english, "subcategories.{$subcategory->value}.label"))->toBeString()->not->toBe('')
            ->and(data_get($english, "subcategories.{$subcategory->value}.description"))->toBeString()->not->toBe('');
    }
});

it('includes every taxonomy translation in every supported non-English locale', function () {
    $catalogue = json_decode(file_get_contents(database_path('seeders/data/interface-translations.json')), true, flags: JSON_THROW_ON_ERROR);
    $translations = collect($catalogue['translations'])
        ->where('group', 'ingredients')
        ->keyBy('key');

    $keys = collect(IngredientCategory::cases())
        ->flatMap(fn (IngredientCategory $category): array => [
            "categories.{$category->value}.label",
            "categories.{$category->value}.description",
        ])
        ->merge(collect(IngredientSubcategory::cases())->flatMap(fn (IngredientSubcategory $subcategory): array => [
            "subcategories.{$subcategory->value}.label",
            "subcategories.{$subcategory->value}.description",
        ]));

    foreach ($keys as $key) {
        expect($translations->has($key))->toBeTrue("Missing ingredients translation key: {$key}");

        foreach ($catalogue['locales'] as $locale) {
            expect(data_get($translations->get($key), "text.{$locale}"))->toBeString()->not->toBe('', "Missing {$locale} translation for {$key}");
        }
    }
});

it('labels the stable preservatives subcategory for preservatives and preservation boosters', function (): void {
    $english = require lang_path('en/ingredients.php');

    expect(IngredientSubcategory::Preservatives->value)->toBe('preservatives')
        ->and(data_get($english, 'subcategories.preservatives.label'))
        ->toBe('Preservatives & preservation boosters');
});

it('stores taxonomy provenance and specialist flags on ingredients and function assignments', function () {
    expect(Schema::hasColumns('ingredients', [
        'subcategory',
        'taxonomy_source',
        'taxonomy_reviewed_at',
        'taxonomy_reviewed_by_user_id',
        'cosing_reference',
        'is_soap_saponification_trusted',
        'requires_aromatic_compliance',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_function_ingredient', [
            'source',
            'source_reference',
            'source_checked_at',
            'assigned_by_user_id',
        ]))->toBeTrue();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Silicones,
        'subcategory' => IngredientSubcategory::NonvolatileSilicones,
        'taxonomy_source' => 'platform_curated',
        'is_soap_saponification_trusted' => false,
        'requires_aromatic_compliance' => false,
    ]);

    expect($ingredient->fresh()->subcategory)->toBe(IngredientSubcategory::NonvolatileSilicones)
        ->and($ingredient->fresh()->taxonomy_source)->toBe('platform_curated');
});
