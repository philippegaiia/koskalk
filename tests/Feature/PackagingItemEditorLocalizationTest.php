<?php

use App\Livewire\Dashboard\PackagingItemEditor;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('uses the approved task-focused copy on the add packaging page', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.create'))
        ->assertSuccessful()
        ->assertSeeText('Add packaging')
        ->assertSeeText('Add packaging to your library.')
        ->assertSeeText('Save the name, unit price, image, and notes once, then reuse this packaging in your products and costing.')
        ->assertSeeText('Back to packaging')
        ->assertSeeText('Packaging details')
        ->assertSeeText('Add the information you need to identify and cost this packaging.')
        ->assertSeeText('Name')
        ->assertSee('placeholder="e.g. 100 g kraft soap box"', false)
        ->assertSeeText('Unit price (GBP)')
        ->assertSeeText('Packaging image')
        ->assertSeeText('Optional square image shown in your packaging library and selectors.')
        ->assertSeeText('Notes')
        ->assertSeeText('Add the size, material, supplier reference, or anything else useful.')
        ->assertDontSeeText('Packaging item')
        ->assertDontSeeText('Create a reusable packaging item for recipe costing.')
        ->assertDontSeeText('catalog rows');
});

it('uses the approved edit packaging copy', function () {
    $user = User::factory()->create();
    $packaging = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Kraft soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.edit', $packaging))
        ->assertSuccessful()
        ->assertSeeText('Kraft soap box')
        ->assertSeeText('Edit packaging details.')
        ->assertSeeText('Update the details used in your products and costing.')
        ->assertSeeText('Save changes')
        ->assertDontSeeText('Refine the packaging record and keep it ready for costing.')
        ->assertDontSeeText('Save packaging item');
});

it('loads packaging editor interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.create.page_title' => 'Ajouter un emballage',
        'editor.create.heading' => 'Ajoutez un emballage à votre bibliothèque.',
        'editor.create.intro' => 'Enregistrez une fois son nom, son prix unitaire, son image et vos notes, puis réutilisez cet emballage dans vos produits et vos coûts.',
        'editor.actions.back' => 'Retour aux emballages',
        'editor.actions.create' => 'Ajouter l’emballage',
        'editor.form.section' => 'Détails de l’emballage',
        'editor.form.description' => 'Ajoutez les informations nécessaires pour identifier et chiffrer cet emballage.',
        'editor.form.name.label' => 'Nom',
        'editor.form.name.placeholder' => 'p. ex. boîte kraft pour savon de 100 g',
        'editor.form.unit_price' => 'Prix unitaire (:currency)',
        'editor.form.image.label' => 'Image de l’emballage',
        'editor.form.image.helper' => 'Image carrée facultative affichée dans votre bibliothèque et les sélecteurs d’emballages.',
        'editor.form.notes.label' => 'Notes',
        'editor.form.notes.helper' => 'Ajoutez le format, le matériau, la référence fournisseur ou toute autre information utile.',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'packaging',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('packaging-items.create'))
        ->assertSuccessful()
        ->assertSeeText('Ajouter un emballage')
        ->assertSeeText('Ajoutez un emballage à votre bibliothèque.')
        ->assertSeeText('Enregistrez une fois son nom, son prix unitaire, son image et vos notes, puis réutilisez cet emballage dans vos produits et vos coûts.')
        ->assertSeeText('Retour aux emballages')
        ->assertSeeText('Détails de l’emballage')
        ->assertSee('placeholder="p. ex. boîte kraft pour savon de 100 g"', false)
        ->assertSeeText('Prix unitaire (EUR)')
        ->assertSeeText('Image de l’emballage')
        ->assertSeeText('Ajouter l’emballage');
});

it('loads the saved packaging status from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $packaging = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Boîte kraft',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'packaging',
        'key' => 'editor.status.saved',
        'text' => ['fr' => 'Modifications enregistrées.'],
    ]);

    App::setLocale('fr');

    $this->actingAs($user);

    Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packaging])
        ->set('data.notes', 'Boîte de 100 g')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', 'Modifications enregistrées.');
});

it('keeps every packaging editor string in the packaging translation group', function () {
    $copy = require lang_path('en/packaging.php');

    expect($copy)->toHaveKeys([
        'editor.create.page_title',
        'editor.create.heading',
        'editor.create.intro',
        'editor.edit.heading',
        'editor.edit.intro',
        'editor.actions.back',
        'editor.actions.create',
        'editor.actions.save',
        'editor.form.section',
        'editor.form.description',
        'editor.form.name.label',
        'editor.form.name.placeholder',
        'editor.form.unit_price',
        'editor.form.image.label',
        'editor.form.image.helper',
        'editor.form.notes.label',
        'editor.form.notes.helper',
        'editor.status.auth_required',
        'editor.status.created',
        'editor.status.saved',
    ]);
});
