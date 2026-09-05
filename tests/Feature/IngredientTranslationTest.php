<?php

use App\Data\IngredientTranslationWriteIntent;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientTranslationOrigin;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\ProductFamily;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\IngredientTranslationService;
use App\Services\IngredientTranslationSourceFingerprint;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores ingredient translations in a dedicated constrained table', function () {
    expect(Schema::hasColumn('ingredients', 'saponification_name'))->toBeTrue();

    expect(Schema::hasColumns('ingredient_translations', [
        'id',
        'ingredient_id',
        'locale',
        'display_name',
        'saponification_name',
        'info_markdown',
        'source_fingerprint',
        'origin',
        'prompt_version',
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
        'saponification_name' => 'Olive',
        'info_markdown' => 'English guidance',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'saponification_name' => 'Olive',
            'info_markdown' => 'Conseils en français',
        ]);

    expect($ingredient->localizedDisplayName('fr'))->toBe('Huile d’olive')
        ->and($ingredient->localizedSaponificationName('fr'))->toBe('Olive')
        ->and($ingredient->localizedInfoMarkdown('fr'))->toBe('Conseils en français')
        ->and($ingredient->localizedDisplayName('fr_FR'))->toBe('Huile d’olive')
        ->and($ingredient->localizedDisplayName('de'))->toBe('Olive Oil')
        ->and($ingredient->localizedSaponificationName('de'))->toBe('Olive')
        ->and($ingredient->localizedInfoMarkdown('de'))->toBe('English guidance')
        ->and($ingredient->localizedDisplayName('en'))->toBe('Olive Oil')
        ->and($ingredient->localizedSaponificationName('en'))->toBe('Olive');
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
            'saponification_name' => '  Olive  ',
            'info_markdown' => '   ',
        ],
    ]);

    expect($ingredient->translations()->get()->toArray())
        ->toHaveCount(1)
        ->and($ingredient->translations()->firstOrFail()->only([
            'locale',
            'display_name',
            'saponification_name',
            'info_markdown',
        ]))->toBe([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'saponification_name' => 'Olive',
            'info_markdown' => null,
        ])
        ->and(app(IngredientTranslationService::class)->formData($ingredient))->toBe([
            [
                'locale' => 'fr',
                'display_name' => 'Huile d’olive',
                'saponification_name' => 'Olive',
                'info_markdown' => null,
                'origin' => 'reviewer_edited',
                'freshness' => 'current',
                'is_stale' => false,
            ],
        ]);
});

it('derives outdated translations when canonical English guidance changes', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => '## Overview\nOriginal guidance',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => '## Vue d’ensemble\nConseils originaux',
    ]], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');

    $ingredient->update(['info_markdown' => '## Overview\nUpdated guidance']);

    expect(collect($service->formData($ingredient))->first())->toMatchArray([
        'info_markdown' => '## Vue d’ensemble\nConseils originaux',
        'origin' => 'ai_generated',
        'freshness' => 'outdated',
        'is_stale' => true,
    ]);
});

it('keeps guidance translations current when only canonical identity names change', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'saponification_name' => 'Olive oil soap',
        'info_markdown' => '## Overview\nOriginal guidance',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'saponification_name' => 'Savon d’huile d’olive',
        'info_markdown' => '## Vue d’ensemble\nConseils originaux',
    ]], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');

    $ingredient->update([
        'display_name' => 'High-oleic olive oil',
        'saponification_name' => 'High-oleic olive oil soap',
    ]);

    expect(collect($service->formData($ingredient))->first())->toMatchArray([
        'freshness' => 'current',
        'is_stale' => false,
    ]);
});

it('marks only changed locales as reviewer edited while preserving other metadata', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    SupportedLocale::factory()->create(['code' => 'de']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => '## Overview\nOriginal guidance',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'Conseils FR'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'Hinweise DE'],
    ], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $german = $ingredient->translations()->where('locale', 'de')->firstOrFail();

    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'Conseils FR révisés'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'Hinweise DE'],
    ]);

    $french = $ingredient->translations()->where('locale', 'fr')->firstOrFail();
    $german->refresh();
    expect($french->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and($french->prompt_version)->toBeNull()
        ->and($french->source_fingerprint)->toBe(app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient))
        ->and($german->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($german->prompt_version)->toBe('ingredient-guidance-localization-v1')
        ->and($german->info_markdown)->toBe('Hinweise DE');
});

it('refreshes stale metadata for an identical translation with an explicit locale intent', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'Original English',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => 'Conseils originaux',
    ]], IngredientTranslationOrigin::AiGenerated, 'localization-v1');

    $ingredient->update(['info_markdown' => 'Updated English']);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => 'Conseils originaux',
    ]], writeIntents: [
        'fr' => new IngredientTranslationWriteIntent(
            IngredientTranslationOrigin::AiGenerated,
            'localization-v2',
            refreshMetadata: true,
        ),
    ]);

    $translation = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    expect($translation->source_fingerprint)
        ->toBe(app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient))
        ->and($translation->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($translation->prompt_version)->toBe('localization-v2')
        ->and($translation->display_name)->toBe('Huile d’olive')
        ->and($translation->info_markdown)->toBe('Conseils originaux');
});

it('applies reviewer provenance only to the locale with a reviewer intent', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    SupportedLocale::factory()->create(['code' => 'de']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'Original English',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'Conseils FR'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'Hinweise DE'],
    ], IngredientTranslationOrigin::AiGenerated, 'localization-v1');

    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'Conseils FR révisés'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'Hinweise DE'],
    ], writeIntents: [
        'fr' => new IngredientTranslationWriteIntent(
            IngredientTranslationOrigin::ReviewerEdited,
            null,
        ),
    ]);

    $translations = $ingredient->translations()->get()->keyBy('locale');
    expect($translations['fr']->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and($translations['fr']->prompt_version)->toBeNull()
        ->and($translations['fr']->info_markdown)->toBe('Conseils FR révisés')
        ->and($translations['de']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($translations['de']->prompt_version)->toBe('localization-v1')
        ->and($translations['de']->info_markdown)->toBe('Hinweise DE');
});

it('preserves all metadata for an identical translation without a locale intent', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'Original English',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => 'Conseils originaux',
    ]], IngredientTranslationOrigin::AiGenerated, 'localization-v1');
    $original = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();

    $ingredient->update(['info_markdown' => 'Updated English']);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => 'Conseils originaux',
    ]]);

    $translation = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    expect($translation->source_fingerprint)->toBe($original->source_fingerprint)
        ->and($translation->origin)->toBe($original->origin)
        ->and($translation->prompt_version)->toBe($original->prompt_version)
        ->and($translation->display_name)->toBe($original->display_name)
        ->and($translation->saponification_name)->toBe($original->saponification_name)
        ->and($translation->info_markdown)->toBe($original->info_markdown);
});

it('preserves metadata for identical content when an explicit intent does not refresh it', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'Original English',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'saponification_name' => 'Huile d’olive saponifiée',
        'info_markdown' => 'Conseils originaux',
    ]], IngredientTranslationOrigin::AiGenerated, 'localization-v1');
    $original = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();

    $ingredient->update(['info_markdown' => 'Updated English']);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'saponification_name' => 'Huile d’olive saponifiée',
        'info_markdown' => 'Conseils originaux',
    ]], writeIntents: [
        'fr' => new IngredientTranslationWriteIntent(
            IngredientTranslationOrigin::ReviewerEdited,
            null,
            refreshMetadata: false,
        ),
    ]);

    $translation = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    expect($translation->source_fingerprint)->toBe($original->source_fingerprint)
        ->and($translation->origin)->toBe($original->origin)
        ->and($translation->prompt_version)->toBe($original->prompt_version)
        ->and($translation->display_name)->toBe($original->display_name)
        ->and($translation->saponification_name)->toBe($original->saponification_name)
        ->and($translation->info_markdown)->toBe($original->info_markdown);
});

it('rejects a write intent value that is not the intent DTO', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();
    $translation = IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Original translation',
    ]);

    $exception = null;
    try {
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            [['locale' => 'fr', 'display_name' => 'Updated translation']],
            writeIntents: ['fr' => 'invalid'],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('write_intents.0.intent')
        ->and($exception->errors()['write_intents.0.intent'][0])
        ->toBe(__('ingredients.editor.validation.translation_write_intent_invalid'));

    expect($translation->fresh()->display_name)->toBe('Original translation');
});

it('rejects a blank write intent locale with a required error', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();

    $exception = null;
    try {
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            [['locale' => 'fr', 'display_name' => 'Huile d’olive']],
            writeIntents: [
                '' => new IngredientTranslationWriteIntent(IngredientTranslationOrigin::AiGenerated, 'v1'),
            ],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('write_intents.0.locale')
        ->and($exception->errors()['write_intents.0.locale'][0])
        ->toBe(__('ingredients.editor.validation.translation_write_intent_locale_required'));
});

it('rejects an overlength write intent locale with a max error', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();

    $exception = null;
    try {
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            [['locale' => 'fr', 'display_name' => 'Huile d’olive']],
            writeIntents: [
                str_repeat('f', 17) => new IngredientTranslationWriteIntent(
                    IngredientTranslationOrigin::AiGenerated,
                    'v1',
                ),
            ],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('write_intents.0.locale')
        ->and($exception->errors()['write_intents.0.locale'][0])
        ->toBe(__('ingredients.editor.validation.translation_write_intent_locale_max', ['max' => 16]));
});

it('rejects write intent locale keys that collide after trimming', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();

    $exception = null;
    try {
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            [['locale' => 'fr', 'display_name' => 'Huile d’olive']],
            writeIntents: [
                'fr' => new IngredientTranslationWriteIntent(IngredientTranslationOrigin::AiGenerated, 'v1'),
                ' fr ' => new IngredientTranslationWriteIntent(IngredientTranslationOrigin::AiGenerated, 'v2'),
            ],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('write_intents.1.locale')
        ->and($exception->errors()['write_intents.1.locale'][0])
        ->toBe(__('ingredients.editor.validation.translation_write_intent_locale_distinct'));
});

it('rejects a write intent locale absent from the submitted translations', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    SupportedLocale::factory()->create(['code' => 'de']);
    $ingredient = Ingredient::factory()->create();

    $exception = null;
    try {
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            [['locale' => 'fr', 'display_name' => 'Huile d’olive']],
            writeIntents: [
                'de' => new IngredientTranslationWriteIntent(IngredientTranslationOrigin::AiGenerated, 'v1'),
            ],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('write_intents.0.locale')
        ->and($exception->errors()['write_intents.0.locale'][0])
        ->toBe(__('ingredients.editor.validation.translation_write_intent_locale_missing'));
});

it('marks a changed English source and an edited locale independently in one save', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    SupportedLocale::factory()->create(['code' => 'de']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'Original English',
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'FR original'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'DE original'],
    ], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');

    $ingredient->update(['info_markdown' => 'Updated English']);
    $service->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => 'FR edited'],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => 'DE original'],
    ]);

    $rows = collect($service->formData($ingredient))->keyBy('locale');
    expect($rows['fr'])->toMatchArray(['origin' => 'reviewer_edited', 'freshness' => 'current', 'is_stale' => false])
        ->and($rows['de'])->toMatchArray(['origin' => 'ai_generated', 'freshness' => 'outdated', 'is_stale' => true]);
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
        'category' => IngredientCategory::Other,
        'display_name' => 'Olive Powder',
    ]);
    $fallbackIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
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

it('delivers canonical category and subcategory metadata to the recipe workbench', function (): void {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'display_name' => 'Olive Oil',
    ]);

    $catalog = collect(app(RecipeWorkbenchIngredientCatalogBuilder::class)->build(null, $productFamily))->keyBy('id');

    expect($catalog[$ingredient->id])
        ->toMatchArray([
            'category' => 'lipids',
            'category_label' => 'Oils, butters & fats',
            'subcategory' => 'vegetable_oils',
            'subcategory_label' => 'Vegetable oils',
        ]);
});

it('uses active and neutral aliases in the workbench and English only as a fallback', function (): void {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $localized = Ingredient::factory()->create(['category' => IngredientCategory::Other]);
    $fallback = Ingredient::factory()->create(['category' => IngredientCategory::Other]);

    $localized->aliases()->createMany([
        ['locale' => 'fr', 'name' => 'Nigelle', 'normalized_name' => 'nigelle', 'kind' => 'common'],
        ['locale' => 'und', 'name' => 'Nigella sativa', 'normalized_name' => 'nigella sativa', 'kind' => 'botanical'],
        ['locale' => 'en', 'name' => 'Black cumin', 'normalized_name' => 'black cumin', 'kind' => 'common'],
    ]);
    $fallback->aliases()->createMany([
        ['locale' => 'und', 'name' => 'Butyrospermum parkii', 'normalized_name' => 'butyrospermum parkii', 'kind' => 'botanical'],
        ['locale' => 'en', 'name' => 'Shea butter', 'normalized_name' => 'shea butter', 'kind' => 'common'],
    ]);

    $catalog = collect(app(RecipeWorkbenchIngredientCatalogBuilder::class)->build(null, $productFamily))->keyBy('id');

    expect($catalog[$localized->id]['aliases'])->toBe(['Nigelle', 'Nigella sativa'])
        ->and($catalog[$fallback->id]['aliases'])->toBe(['Butyrospermum parkii', 'Shea butter']);
});

it('does not search an English alias when an active-locale alias exists', function (): void {
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $user = User::factory()->create(['locale' => 'fr']);
    $localized = Ingredient::factory()->create([
        'display_name' => 'Nigella Seed Oil',
        'inci_name' => 'NIGELLA SATIVA SEED OIL',
    ]);
    $fallback = Ingredient::factory()->create([
        'display_name' => 'Butyrospermum Parkii Butter',
        'inci_name' => 'BUTYROSPERMUM PARKII BUTTER',
    ]);
    $localized->aliases()->createMany([
        ['locale' => 'fr', 'name' => 'Nigelle', 'normalized_name' => 'nigelle', 'kind' => 'common'],
        ['locale' => 'en', 'name' => 'Black cumin', 'normalized_name' => 'black cumin', 'kind' => 'common'],
    ]);
    $fallback->aliases()->create([
        'locale' => 'en',
        'name' => 'Shea butter',
        'normalized_name' => 'shea butter',
        'kind' => 'common',
    ]);

    $this->actingAs($user)
        ->getJson(route('ingredients.search-platform', ['q' => 'black cumin']))
        ->assertSuccessful()
        ->assertJsonMissing(['id' => $localized->id]);

    $this->actingAs($user)
        ->getJson(route('ingredients.search-platform', ['q' => 'shea butter']))
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $fallback->id]);
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
        'category' => IngredientCategory::Other,
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
        ->create(['category' => IngredientCategory::Other]);

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
