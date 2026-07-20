<?php

use App\Models\InterfaceTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

it('renders the authenticated side menu with contextual translations', function (
    string $locale,
    array $translations,
) {
    $keys = [
        'items.overview',
        'items.formulas',
        'items.ingredients',
        'items.packaging',
        'items.compliance',
        'items.account',
        'items.settings',
        'actions.sign_out',
    ];

    foreach (array_combine($keys, $translations) as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'navigation',
            'key' => $key,
            'text' => [$locale => $translation],
        ]);
    }

    App::setLocale($locale);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSeeText('Admin');

    foreach ($translations as $translation) {
        $response->assertSeeText($translation);
    }
})->with([
    'French' => ['fr', ['Aperçu', 'Produits', 'Ingrédients', 'Emballages', 'Conformité', 'Compte', 'Paramètres', 'Se déconnecter']],
    'Spanish' => ['es', ['Resumen', 'Productos', 'Ingredientes', 'Envases', 'Cumplimiento', 'Cuenta', 'Ajustes', 'Cerrar sesión']],
    'German' => ['de', ['Übersicht', 'Produkte', 'Inhaltsstoffe', 'Verpackungen', 'Konformität', 'Konto', 'Einstellungen', 'Abmelden']],
    'Italian' => ['it', ['Panoramica', 'Prodotti', 'Ingredienti', 'Imballaggi', 'Conformità', 'Account', 'Impostazioni', 'Esci']],
    'Dutch' => ['nl', ['Overzicht', 'Producten', 'Ingrediënten', 'Verpakkingen', 'Regelgeving', 'Account', 'Instellingen', 'Uitloggen']],
]);

it('renders the dashboard with contextual translations', function (
    string $locale,
    array $translations,
) {
    foreach ($translations as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'dashboard',
            'key' => $key,
            'text' => [$locale => $translation],
        ]);
    }

    App::setLocale($locale);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful();

    foreach ($translations as $translation) {
        $response->assertSeeText($translation);
    }
})->with([
    'French' => ['fr', [
        'title' => 'Aperçu',
        'create.heading' => 'Créer un produit',
        'create.soap' => 'Nouveau savon',
        'create.cosmetic' => 'Nouveau produit cosmétique',
        'library.heading' => 'Vos produits',
        'library.products' => 'Produits',
        'library.ingredients' => 'Ingrédients',
        'library.locked_products' => 'Produits verrouillés',
    ]],
    'Spanish' => ['es', [
        'title' => 'Resumen',
        'create.heading' => 'Crear un producto',
        'create.soap' => 'Nuevo jabón',
        'create.cosmetic' => 'Nuevo producto cosmético',
        'library.heading' => 'Tus productos',
        'library.products' => 'Productos',
        'library.ingredients' => 'Ingredientes',
        'library.locked_products' => 'Productos bloqueados',
    ]],
    'German' => ['de', [
        'title' => 'Übersicht',
        'create.heading' => 'Produkt erstellen',
        'create.soap' => 'Neue Seife',
        'create.cosmetic' => 'Neues Kosmetikprodukt',
        'library.heading' => 'Ihre Produkte',
        'library.products' => 'Produkte',
        'library.ingredients' => 'Inhaltsstoffe',
        'library.locked_products' => 'Gesperrte Produkte',
    ]],
    'Italian' => ['it', [
        'title' => 'Panoramica',
        'create.heading' => 'Crea un prodotto',
        'create.soap' => 'Nuovo sapone',
        'create.cosmetic' => 'Nuovo prodotto cosmetico',
        'library.heading' => 'I tuoi prodotti',
        'library.products' => 'Prodotti',
        'library.ingredients' => 'Ingredienti',
        'library.locked_products' => 'Prodotti bloccati',
    ]],
    'Dutch' => ['nl', [
        'title' => 'Overzicht',
        'create.heading' => 'Product maken',
        'create.soap' => 'Nieuwe zeep',
        'create.cosmetic' => 'Nieuw cosmeticaproduct',
        'library.heading' => 'Je producten',
        'library.products' => 'Producten',
        'library.ingredients' => 'Ingrediënten',
        'library.locked_products' => 'Vergrendelde producten',
    ]],
]);
