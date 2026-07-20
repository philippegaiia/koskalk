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
    'French' => ['fr', ['Aperçu', 'Formules', 'Ingrédients', 'Emballages', 'Conformité', 'Compte', 'Paramètres', 'Se déconnecter']],
    'Spanish' => ['es', ['Resumen', 'Fórmulas', 'Ingredientes', 'Envases', 'Cumplimiento', 'Cuenta', 'Ajustes', 'Cerrar sesión']],
    'German' => ['de', ['Übersicht', 'Rezepturen', 'Inhaltsstoffe', 'Verpackungen', 'Konformität', 'Konto', 'Einstellungen', 'Abmelden']],
    'Italian' => ['it', ['Panoramica', 'Formule', 'Ingredienti', 'Imballaggi', 'Conformità', 'Account', 'Impostazioni', 'Esci']],
    'Dutch' => ['nl', ['Overzicht', 'Formules', 'Ingrediënten', 'Verpakkingen', 'Regelgeving', 'Account', 'Instellingen', 'Uitloggen']],
]);
