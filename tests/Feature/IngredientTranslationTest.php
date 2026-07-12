<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\ProductFamily;
use App\Models\SupportedLocale;
use App\Models\User;
use App\OwnerType;
use App\Services\IngredientTranslationService;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores ingredient translations in a dedicated constrained table', function () {
    expect(Schema::hasColumns('ingredient_translations', [
        'id',
        'ingredient_id',
        'locale',
        'display_name',
        'info_markdown',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('relates translations to an ingredient and deletes them with it', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();
    $translation = IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
        ]);

    expect($ingredient->translations->modelKeys())->toBe([$translation->id]);

    $ingredient->delete();

    expect(IngredientTranslation::query()->whereKey($translation->id)->exists())->toBeFalse();
});

it('allows only one translation per ingredient and locale', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();

    IngredientTranslation::factory()
        ->for($ingredient)
        ->create(['locale' => 'fr']);

    expect(fn () => IngredientTranslation::factory()
        ->for($ingredient)
        ->create(['locale' => 'fr']))
        ->toThrow(QueryException::class);
});

it('requires translations to use a registered locale', function () {
    $ingredient = Ingredient::factory()->create();

    expect(fn () => IngredientTranslation::factory()
        ->for($ingredient)
        ->create(['locale' => 'xx']))
        ->toThrow(QueryException::class);
});

it('resolves translated ingredient fields with English fallback', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'English guidance',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'info_markdown' => 'Conseils en français',
        ]);

    expect($ingredient->localizedDisplayName('fr'))->toBe('Huile d’olive')
        ->and($ingredient->localizedInfoMarkdown('fr'))->toBe('Conseils en français')
        ->and($ingredient->localizedDisplayName('fr_FR'))->toBe('Huile d’olive')
        ->and($ingredient->localizedDisplayName('de'))->toBe('Olive Oil')
        ->and($ingredient->localizedInfoMarkdown('de'))->toBe('English guidance')
        ->and($ingredient->localizedDisplayName('en'))->toBe('Olive Oil');
});

it('falls back when a translated field is empty', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'English guidance',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => null,
            'info_markdown' => null,
        ]);

    expect($ingredient->localizedDisplayName('fr'))->toBe('Olive Oil')
        ->and($ingredient->localizedInfoMarkdown('fr'))->toBe('English guidance');
});

it('always keeps private ingredient content as authored', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Mon huile',
        'info_markdown' => 'Mes notes',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Should not appear',
            'info_markdown' => 'Should not appear',
        ]);

    expect($ingredient->localizedDisplayName('fr'))->toBe('Mon huile')
        ->and($ingredient->localizedInfoMarkdown('fr'))->toBe('Mes notes');
});

it('normalizes and synchronizes platform ingredient translations', function () {
    SupportedLocale::factory()->create(['code' => 'fr', 'sort_order' => 20]);
    SupportedLocale::factory()->create(['code' => 'de', 'sort_order' => 10]);
    $ingredient = Ingredient::factory()->create();
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'de',
            'display_name' => 'Alte Übersetzung',
        ]);

    app(IngredientTranslationService::class)->sync($ingredient, [
        [
            'locale' => 'fr',
            'display_name' => '  Huile d’olive  ',
            'info_markdown' => '   ',
        ],
    ]);

    expect($ingredient->translations()->get()->toArray())
        ->toHaveCount(1)
        ->and($ingredient->translations()->firstOrFail()->only([
            'locale',
            'display_name',
            'info_markdown',
        ]))->toBe([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'info_markdown' => null,
        ])
        ->and(app(IngredientTranslationService::class)->formData($ingredient))->toBe([
            [
                'locale' => 'fr',
                'display_name' => 'Huile d’olive',
                'info_markdown' => null,
            ],
        ]);
});

it('rejects invalid platform translation state', function (array $rows) {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();

    expect(fn () => app(IngredientTranslationService::class)->sync($ingredient, $rows))
        ->toThrow(ValidationException::class);
})->with([
    'English locale' => [[
        ['locale' => 'en', 'display_name' => 'Olive Oil'],
    ]],
    'unknown locale' => [[
        ['locale' => 'xx', 'display_name' => 'Unknown'],
    ]],
    'duplicate locale' => [[
        ['locale' => 'fr', 'display_name' => 'Huile'],
        ['locale' => 'fr', 'display_name' => 'Huile d’olive'],
    ]],
    'empty translation' => [[
        ['locale' => 'fr', 'display_name' => ' ', 'info_markdown' => null],
    ]],
]);

it('rejects translations for private ingredients', function () {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);

    expect(fn () => app(IngredientTranslationService::class)->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Traduction'],
    ]))->toThrow(ValidationException::class);
});

it('delivers localized platform names to the recipe workbench with English fallback', function () {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $translatedIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Olive Powder',
    ]);
    $fallbackIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Sea Salt',
    ]);
    IngredientTranslation::factory()
        ->for($translatedIngredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Poudre d’olive',
        ]);

    $catalog = app(RecipeWorkbenchIngredientCatalogBuilder::class)->build(null, $productFamily);

    expect(collect($catalog)->firstWhere('id', $translatedIngredient->id)['name'])
        ->toBe('Poudre d’olive')
        ->and(collect($catalog)->firstWhere('id', $fallbackIngredient->id)['name'])
        ->toBe('Sea Salt');
});

it('keeps private ingredient names authored in localized workbench catalogs', function () {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create();
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Mon argile',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);

    $catalog = app(RecipeWorkbenchIngredientCatalogBuilder::class)->build($user, $productFamily);

    expect(collect($catalog)->firstWhere('id', $ingredient->id))
        ->toMatchArray([
            'name' => 'Mon argile',
            'is_user_owned' => true,
        ]);
});

it('eager loads workbench translations in one catalog query', function () {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredients = Ingredient::factory()
        ->count(3)
        ->create(['category' => IngredientCategory::Additive]);

    $ingredients->each(fn (Ingredient $ingredient) => IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Nom '.$ingredient->id,
        ]));

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(RecipeWorkbenchIngredientCatalogBuilder::class)->build(null, $productFamily);

    $translationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'ingredient_translations'));

    expect($translationQueries)->toHaveCount(1);
});

it('searches platform ingredients by translated or English name and returns the localized name', function () {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
        ]);

    $this->actingAs($user)
        ->getJson(route('ingredients.search-platform', ['q' => 'huile']))
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $ingredient->id,
            'name' => 'Huile d’olive',
        ]);

    $this->actingAs($user)
        ->getJson(route('ingredients.search-platform', ['q' => 'olive']))
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $ingredient->id,
            'name' => 'Huile d’olive',
        ]);
});

it('shows localized platform names and authored private names in the ingredient dashboard', function () {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create();
    $platformIngredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
    ]);
    IngredientTranslation::factory()
        ->for($platformIngredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
        ]);
    Ingredient::factory()->create([
        'display_name' => 'Mon argile',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Huile d’olive')
        ->assertSee('Mon argile');
});
