<?php

use App\Data\IngredientClassificationPromptInput;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\InterfaceTranslation;
use App\Services\IngredientClassificationPromptBuilder;
use App\Services\IngredientDataEntryService;
use Database\Seeders\IngredientFunctionSeeder;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
});

it('defines a shared English label and description for every active COSING function', function (): void {
    $english = require lang_path('en/ingredients.php');

    $this->seed(IngredientFunctionSeeder::class);
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $translations = collect($catalogue['translations'])
        ->where('group', 'ingredients')
        ->keyBy('key');

    foreach (IngredientFunction::query()->where('is_active', true)->get() as $function) {
        expect(data_get($english, "functions.{$function->key}.label"))
            ->toBeString()
            ->not->toBe('')
            ->and(data_get($english, "functions.{$function->key}.description"))
            ->toBeString()
            ->not->toBe('')
            ->and($translations->has("functions.{$function->key}.label"))->toBeTrue()
            ->and($translations->has("functions.{$function->key}.description"))->toBeTrue();

        foreach ($catalogue['locales'] as $locale) {
            expect(data_get($translations->get("functions.{$function->key}.label"), "text.{$locale}"))
                ->toBeString()
                ->not->toBe('')
                ->and(data_get($translations->get("functions.{$function->key}.description"), "text.{$locale}"))
                ->toBeString()
                ->not->toBe('');
        }
    }
});

it('resolves localized COSING names and descriptions with canonical English fallback', function (): void {
    $function = IngredientFunction::factory()->create([
        'key' => 'emollient',
        'name' => 'Emollient',
        'description' => 'Softens and smooths the skin.',
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'ingredients',
        'key' => 'functions.emollient.label',
        'text' => ['fr' => 'Émollient'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'ingredients',
        'key' => 'functions.emollient.description',
        'text' => ['fr' => 'Adoucit et lisse la peau.'],
    ]);

    expect($function->localizedName('fr'))
        ->toBe('Émollient')
        ->and($function->localizedDescription('fr'))
        ->toBe('Adoucit et lisse la peau.')
        ->and($function->localizedName('de'))
        ->toBe('Emollient')
        ->and($function->localizedDescription('de'))
        ->toBe('Softens and smooths the skin.');
});

it('localizes taxonomy and function labels in the classification prompt without changing backing values', function (): void {
    IngredientFunction::factory()->create([
        'key' => 'emollient',
        'name' => 'Emollient',
        'description' => 'Softens and smooths the skin.',
        'is_active' => true,
    ]);

    foreach ([
        ['key' => 'categories.lipids.label', 'text' => 'Lipides'],
        ['key' => 'subcategories.vegetable_oils.label', 'text' => 'Huiles végétales'],
        ['key' => 'functions.emollient.label', 'text' => 'Émollient'],
    ] as $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $translation['key'],
            'text' => ['fr' => $translation['text']],
        ]);
    }

    $prompt = app(IngredientClassificationPromptBuilder::class)->build(
        new IngredientClassificationPromptInput(
            name: 'Olive oil',
            inciName: 'OLEA EUROPAEA FRUIT OIL',
            casNumber: null,
            ecNumber: null,
            supplierNotes: null,
            responseLocale: 'fr',
        ),
    );

    expect($prompt)
        ->toContain('Lipides (lipids)')
        ->toContain('Huiles végétales (vegetable_oils)')
        ->toContain('Émollient (emollient)')
        ->toContain(IngredientCategory::Lipids->value)
        ->toContain(IngredientSubcategory::VegetableOils->value);
});

it('localizes verified COSING names in workspace data-entry state', function (): void {
    $function = IngredientFunction::factory()->create([
        'key' => 'emollient',
        'name' => 'Emollient',
        'description' => 'Softens and smooths the skin.',
    ]);
    $ingredient = Ingredient::factory()->create();
    $ingredient->functions()->attach($function->id, [
        'source' => 'cosing',
        'source_reference' => 'CosIng reference',
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'ingredients',
        'key' => 'functions.emollient.label',
        'text' => ['fr' => 'Émollient'],
    ]);

    app()->setLocale('fr');

    expect(app(IngredientDataEntryService::class)->formData($ingredient)['verified_function_names'])
        ->toBe(['Émollient']);
});
