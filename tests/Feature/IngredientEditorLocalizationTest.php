<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Ingredient;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\OwnerType;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('uses the approved task-focused copy on the add ingredient page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Add ingredient')
        ->assertSeeText('Add an ingredient to your library.')
        ->assertSeeText('Start with the ingredient’s basic details. Composition, soap chemistry, and compliance fields will appear when relevant.')
        ->assertSeeText('Details')
        ->assertSeeText('Ingredient details')
        ->assertSeeText('Name and categorize the ingredient so it appears correctly in your library and formulas.')
        ->assertSeeText('Ingredient type')
        ->assertSeeText('Single ingredient')
        ->assertSeeText('Blend')
        ->assertSeeText('Choose Blend when this ingredient is made from several ingredients.')
        ->assertSeeText('Supplier details')
        ->assertSeeText('Certified organic')
        ->assertSeeText('EU CosIng functions')
        ->assertSeeText('Images and notes')
        ->assertSeeText('Add ingredient')
        ->assertDontSeeText('Create a personal ingredient')
        ->assertDontSeeText('Catalog item type')
        ->assertDontSeeText('Optional workspace context')
        ->assertDontSeeText('Create ingredient');
});

it('uses the approved blend composition copy', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->assertSeeText('Blend composition')
        ->assertSeeText('Add the ingredients in this blend and enter their percentages.')
        ->assertSeeText('Add an ingredient')
        ->assertSee('placeholder="Search by name or INCI"', false)
        ->assertSeeText('Add a new ingredient')
        ->assertSeeText('Enter the basic details now. You can complete the ingredient later.')
        ->assertSeeText('No ingredients added yet')
        ->assertSeeText('Composition source');
});

it('explains how a private carrier oil can be used in saponification', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.category', IngredientCategory::CarrierOil->value)
        ->assertSeeText('Using this oil in saponification')
        ->assertSeeText('A carrier oil created from scratch can be added to a formula, but it cannot be selected for saponification. To use it as a saponified oil, duplicate the matching Soapkraft carrier oil and edit your copy.')
        ->assertSeeText('Saponification values')
        ->assertSeeText('Add the values used to calculate this oil in soap formulas.')
        ->assertSeeText('Enter the KOH SAP as 245 or 0.245. The NaOH SAP is calculated automatically.')
        ->assertDontSeeText('duplicate a platform carrier oil');
});

it('loads ingredient editor interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.create.page_title' => 'Ajouter un ingrédient',
        'editor.create.heading' => 'Ajoutez un ingrédient à votre bibliothèque.',
        'editor.create.intro' => 'Commencez par les informations essentielles.',
        'editor.tabs.details' => 'Détails',
        'editor.details.section' => 'Détails de l’ingrédient',
        'editor.details.type.label' => 'Type d’ingrédient',
        'editor.details.type.single' => 'Ingrédient simple',
        'editor.details.type.blend' => 'Mélange',
        'editor.actions.create' => 'Ajouter l’ingrédient',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Ajouter un ingrédient')
        ->assertSeeText('Ajoutez un ingrédient à votre bibliothèque.')
        ->assertSeeText('Commencez par les informations essentielles.')
        ->assertSeeText('Détails de l’ingrédient')
        ->assertSeeText('Type d’ingrédient')
        ->assertSeeText('Ingrédient simple')
        ->assertSeeText('Mélange')
        ->assertSeeText('Ajouter l’ingrédient');
});

it('loads the saved ingredient status from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Glycérine',
        'category' => IngredientCategory::Additive,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'ingredients',
        'key' => 'editor.status.saved',
        'text' => ['fr' => 'Modifications enregistrées.'],
    ]);

    App::setLocale('fr');
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', 'Modifications enregistrées.');
});

it('keeps every ingredient editor string in the ingredients translation group', function () {
    $copy = require lang_path('en/ingredients.php');

    expect($copy)->toHaveKeys([
        'editor.create.page_title',
        'editor.create.heading',
        'editor.create.intro',
        'editor.edit.heading',
        'editor.edit.intro',
        'editor.actions.create',
        'editor.actions.save',
        'editor.tabs.details',
        'editor.tabs.composition',
        'editor.tabs.soap_chemistry',
        'editor.tabs.compliance',
        'editor.details.section',
        'editor.details.type.label',
        'editor.details.type.single',
        'editor.details.type.blend',
        'editor.supplier.section',
        'editor.media.section',
        'editor.composition.section',
        'editor.soap.section',
        'editor.compliance.allergens.section',
        'editor.compliance.ifra.section',
        'editor.carrier_oil_warning.heading',
        'editor.carrier_oil_warning.description',
        'editor.status.auth_required',
        'editor.status.invalid',
        'editor.status.created',
        'editor.status.saved',
        'editor.validation.component_unavailable',
        'editor.validation.component_limit',
        'editor.validation.component_duplicate',
        'editor.validation.component_share',
    ]);
});
