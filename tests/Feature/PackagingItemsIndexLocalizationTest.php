<?php

use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserPackagingItem;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('uses the approved packaging library copy and an unlabeled thumbnail column', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Kraft soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
        'notes' => '100 g',
    ]);

    expect(config('interface-translations.sources.packaging'))->toBe(['*']);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSeeText('Manage packaging for your recipes and costing.')
        ->assertSeeText('Saved items can be reused in your recipes without entering them again.')
        ->assertSeeText('Packaging library')
        ->assertSeeText('Saved packaging available for your recipes and costing.')
        ->assertSeeText('Add packaging')
        ->assertSeeHtml('<th scope="col"><span class="sr-only">Image</span></th>')
        ->assertDontSeeText('Manage packaging used in recipe costing.')
        ->assertDontSeeText('Packaging catalog');
});

it('loads packaging index interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Boîte kraft',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    foreach ([
        'page.title' => 'Emballages',
        'page.heading' => 'Gérez les emballages de vos recettes et de vos coûts.',
        'catalog.heading' => 'Bibliothèque d’emballages',
        'catalog.description' => 'Vos emballages enregistrés, disponibles pour vos recettes et vos coûts.',
        'actions.add' => 'Ajouter un emballage',
        'search.label' => 'Rechercher',
        'table.name' => 'Nom',
        'price.column' => 'Prix unitaire (:currency)',
        'accessibility.edit' => 'Modifier :item',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'packaging',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSeeText('Emballages')
        ->assertSeeText('Gérez les emballages de vos recettes et de vos coûts.')
        ->assertSeeText('Bibliothèque d’emballages')
        ->assertSeeText('Vos emballages enregistrés, disponibles pour vos recettes et vos coûts.')
        ->assertSeeText('Ajouter un emballage')
        ->assertSeeText('Prix unitaire (EUR)')
        ->assertSeeHtml('aria-label="Modifier Boîte kraft"');
});

it('keeps every packaging index string in the packaging translation group', function () {
    $copy = require lang_path('en/packaging.php');

    expect($copy)->toHaveKeys([
        'page.title',
        'page.heading',
        'page.intro',
        'catalog.heading',
        'catalog.description',
        'actions.add',
        'search.placeholder',
        'empty.no_matches',
        'table.image',
        'price.column',
        'accessibility.unit_price',
        'removal.used.description',
        'removal.unused.heading',
        'validation.in_use',
        'status.deleted',
    ]);
});
