<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\OwnerType;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('uses the approved Soapkraft and customer ingredient terminology', function () {
    $user = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    expect(config('interface-translations.sources.ingredients'))->toBe(['*']);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSeeText('Manage ingredients for formulas and costing.')
        ->assertSeeText('Soapkraft ingredients come from the Soapkraft library.')
        ->assertSeeText('Your ingredients')
        ->assertSeeText('Soapkraft')
        ->assertSeeText('Your price / kg (EUR)')
        ->assertDontSeeText('Use platform ingredients or maintain your own.')
        ->assertDontSeeText('Platform ingredients are shared reference records.');
});

it('loads ingredient index interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    Ingredient::factory()->create([
        'display_name' => 'Huile d’olive',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    foreach ([
        'page.heading' => 'Gérez les ingrédients de vos formules et de vos coûts.',
        'catalog.description' => 'Parcourez les ingrédients, mettez à jour vos coûts et gérez ceux qui vous appartiennent.',
        'filters.yours' => 'Vos ingrédients',
        'filters.soapkraft' => 'Soapkraft',
        'price.column' => 'Votre prix / kg (:currency)',
        'duplicate.button' => 'Dupliquer un ingrédient Soapkraft',
        'table.source.yours' => 'Vous',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    foreach ([
        'pagination.rows_per_page' => 'Lignes par page',
        'pagination.summary' => ':first–:last sur :total',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'table',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSeeText('Gérez les ingrédients de vos formules et de vos coûts.')
        ->assertSeeText('Parcourez les ingrédients, mettez à jour vos coûts et gérez ceux qui vous appartiennent.')
        ->assertSeeText('Vos ingrédients')
        ->assertSeeText('Votre prix / kg (EUR)')
        ->assertSeeText('Dupliquer un ingrédient Soapkraft')
        ->assertSeeText('Vous')
        ->assertSeeText('Lignes par page')
        ->assertSeeText('1–1 sur 1');
});

it('keeps every ingredient index interface string in the ingredients translation group', function () {
    $copy = require lang_path('en/ingredients.php');

    expect($copy)
        ->toHaveKeys([
            'actions.add',
            'actions.back_to_dashboard',
            'search.label',
            'empty.no_matches',
            'table.picture',
            'table.source.label',
            'table.source.yours',
            'table.source.soapkraft',
            'duplicate.button',
            'usage.current',
            'removal.delete_heading',
            'removal.replacement.heading',
            'status.deleted',
            'validation.choose_replacement',
            'accessibility.price',
        ]);

    expect(config('interface-translations.sources.table'))->toBe(['*']);
});
