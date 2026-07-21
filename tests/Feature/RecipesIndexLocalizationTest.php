<?php

use App\Models\InterfaceTranslation;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\SupportedLocale;
use App\Models\User;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Visibility;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
    Storage::fake(MediaStorage::recipeDisk());
});

it('uses product-first terminology throughout the recipes index', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $product = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive soap',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $product->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $product->name,
        'is_current' => false,
        'saved_at' => now(),
    ]);

    expect(config('interface-translations.sources.products'))->toBe(['*']);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSeeText('Products')
        ->assertSeeText('Manage your products.')
        ->assertSeeText('Create and manage soap and cosmetic products, including their formulas, packaging, and saved versions.')
        ->assertSeeText('New soap product')
        ->assertSeeText('New cosmetic product')
        ->assertSee('placeholder="Product name, category, or type"', false)
        ->assertSeeText('All categories')
        ->assertSeeText('All types')
        ->assertSeeText('1 product')
        ->assertSeeText('Open workbench')
        ->assertSeeText('View formula & production')
        ->assertSeeText('Duplicate product')
        ->assertSeeText('Lock product')
        ->assertSeeText('This permanently deletes the product, its current formula, and all saved versions. This cannot be undone.')
        ->assertSee('placeholder="Enter the product name to confirm"', false)
        ->assertDontSeeText('Your recipes')
        ->assertDontSeeText('Create soap formula')
        ->assertDontSeeText('matching formulas')
        ->assertDontSeeText('Formula sheet & production')
        ->assertDontSeeText('Paste recipe name to confirm');
});

it('loads product index interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Savon olive',
    ]);

    foreach ([
        'page.title' => 'Produits',
        'page.heading' => 'Gérez vos produits.',
        'page.intro' => 'Créez et gérez vos savons et produits cosmétiques, avec leurs formules, emballages et versions enregistrées.',
        'actions.new_soap' => 'Nouveau savon',
        'actions.open_workbench' => 'Ouvrir l’atelier',
        'filters.search.label' => 'Rechercher',
        'filters.search.placeholder' => 'Nom, catégorie ou type de produit',
        'filters.category.label' => 'Catégorie',
        'filters.category.all' => 'Toutes les catégories',
        'filters.type.all' => 'Tous les types',
        'count.all' => '{1} :count produit|[2,*] :count produits',
        'accessibility.actions' => 'Actions pour :product',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'products',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSeeText('Produits')
        ->assertSeeText('Gérez vos produits.')
        ->assertSeeText('Créez et gérez vos savons et produits cosmétiques, avec leurs formules, emballages et versions enregistrées.')
        ->assertSeeText('Nouveau savon')
        ->assertSee('placeholder="Nom, catégorie ou type de produit"', false)
        ->assertSeeText('Toutes les catégories')
        ->assertSeeText('Tous les types')
        ->assertSeeText('1 produit')
        ->assertSeeText('Ouvrir l’atelier')
        ->assertSeeHtml('aria-label="Actions pour Savon olive"');
});

it('loads the deleted product status from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $product = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Savon olive',
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'products',
        'key' => 'status.deleted',
        'text' => ['fr' => 'Produit supprimé.'],
    ]);

    $this->actingAs($user)
        ->delete(route('recipes.destroy', $product), [
            'confirm_name' => $product->name,
        ])
        ->assertRedirect(route('recipes.index'))
        ->assertSessionHas('status', 'Produit supprimé.');
});

it('keeps every product index string in the products translation group', function () {
    $copy = require lang_path('en/products.php');

    expect($copy)->toHaveKeys([
        'page.title',
        'page.heading',
        'page.intro',
        'auth.heading',
        'actions.new_soap',
        'actions.new_cosmetic',
        'actions.open_workbench',
        'actions.view_formula_production',
        'actions.duplicate',
        'actions.lock',
        'actions.unlock',
        'filters.search.placeholder',
        'filters.category.all',
        'filters.type.all',
        'count.all',
        'count.matching',
        'empty.no_matches',
        'empty.no_items',
        'card.updated',
        'accessibility.actions',
        'deletion.heading',
        'deletion.warning',
        'deletion.confirmation_placeholder',
        'status.duplicated',
        'status.locked',
        'status.unlocked',
        'status.deleted',
        'status.version_deleted',
        'status.last_version_deleted',
        'validation.confirmation_mismatch',
    ]);
});
