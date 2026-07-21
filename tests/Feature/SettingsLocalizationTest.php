<?php

use App\Livewire\Dashboard\SettingsIndex;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses the approved preferences and workspace settings structure', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Soap Studio']);

    expect(config('interface-translations.sources.settings'))->toBe(['*']);

    $this->actingAs($user)
        ->get(route('settings'))
        ->assertSuccessful()
        ->assertSeeText('Manage your display preferences and workspace settings.')
        ->assertSeeText('Preferences')
        ->assertSeeText('Display preferences')
        ->assertSeeText('Choose how Soapkraft displays language and numbers for your account.')
        ->assertSeeText('Save preferences')
        ->assertSeeText('Workspace')
        ->assertDontSeeText('Profile')
        ->assertDontSeeText('Password')
        ->assertDontSeeText('Company')
        ->assertDontSeeText('Save profile')
        ->assertDontSeeText('Update password');
});

it('uses the approved workspace settings copy', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Soap Studio']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('activeTab', 'workspace')
        ->assertSee('Workspace settings')
        ->assertSee('Manage the shared defaults used for products, ingredients, packaging, and costing in this workspace.')
        ->assertSee('Only the workspace owner can change these settings.')
        ->assertSee('Workspace name')
        ->assertSee('Default currency')
        ->assertSee('Used by default for costing and pricing in this workspace.')
        ->assertSee('Search currencies')
        ->assertSee('Save workspace settings')
        ->assertDontSee('Company');
});

it('loads settings interface copy and status messages from the database', function () {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    foreach ([
        'page.intro' => 'Gérez vos préférences d’affichage et les réglages de votre espace de travail.',
        'tabs.preferences' => 'Préférences',
        'tabs.workspace' => 'Espace de travail',
        'preferences.heading' => 'Préférences d’affichage',
        'actions.save_preferences' => 'Enregistrer les préférences',
        'status.preferences_saved' => 'Préférences enregistrées.',
        'workspace.heading' => 'Réglages de l’espace de travail',
        'workspace.name' => 'Nom de l’espace de travail',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'settings',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $user = User::factory()->create(['locale' => 'fr']);
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Atelier savon']);

    App::setLocale('fr');
    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertSee('Gérez vos préférences d’affichage et les réglages de votre espace de travail.')
        ->assertSee('Préférences')
        ->assertSee('Espace de travail')
        ->assertSee('Préférences d’affichage')
        ->call('savePreferences')
        ->assertSet('preferencesMessage', 'Préférences enregistrées.')
        ->set('activeTab', 'workspace')
        ->assertSee('Réglages de l’espace de travail')
        ->assertSee('Nom de l’espace de travail')
        ->assertDontSee('Workspace settings');
});

it('keeps every settings string in the settings translation group', function () {
    $copy = require lang_path('en/settings.php');

    expect($copy)->toHaveKeys([
        'page.title',
        'page.intro',
        'tabs.preferences',
        'tabs.workspace',
        'preferences.heading',
        'preferences.description',
        'workspace.heading',
        'workspace.description',
        'workspace.owner_help',
        'workspace.name',
        'workspace.default_currency',
        'workspace.currency_search',
        'workspace.currency_help',
        'actions.save_preferences',
        'actions.save_workspace',
        'status.preferences_saved',
        'status.workspace_saved',
    ]);
});
