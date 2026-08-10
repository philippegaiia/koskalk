<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Ingredient;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
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
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('data-ingredient-save-bar')
        ->assertSeeHtml('class="sk-btn sk-btn-ghost"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary"')
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
        ->assertSeeText('Identifiers and functions')
        ->assertDontSeeText('Certified organic')
        ->assertSeeText('Verified COSING functions')
        ->assertSeeText('Additional functions')
        ->assertSeeText('Ask an AI assistant to classify this ingredient')
        ->assertSeeText('Generate a prompt for classification, identifier review, and concise professional notes. Enter an ingredient name or INCI first.')
        ->assertSeeText('Generate prompt')
        ->assertSeeText('Copy prompt')
        ->assertSee('data-classification-prompt-copy', escape: false)
        ->assertSee('disabled', escape: false)
        ->assertDontSeeText('Classify the cosmetic or soapmaking ingredient below.')
        ->assertDontSeeText('"name": null')
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
        ->set('data.is_soap_saponification_trusted', true)
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
        'editor.details.soap_trusted' => 'Fiable pour la saponification',
        'editor.details.soap_trusted_helper' => 'Activez uniquement avec une valeur SAP KOH vérifiée.',
        'editor.details.aromatic_compliance' => 'Conformité aromatique requise',
        'editor.details.aromatic_compliance_helper' => 'Active les informations allergènes et IFRA.',
        'editor.supplier.verified_functions' => 'Fonctions COSING vérifiées',
        'editor.supplier.none_verified' => 'Aucune fonction vérifiée',
        'editor.supplier.verified_functions_helper' => 'Fonctions officielles en lecture seule.',
        'editor.supplier.additional_functions' => 'Fonctions supplémentaires',
        'editor.classification_prompt.description' => 'Générez un prompt pour le classement, la vérification des identifiants et de brèves notes professionnelles. Saisissez d’abord le nom de l’ingrédient ou son INCI.',
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
        ->assertSeeText('Fiable pour la saponification')
        ->assertSeeText('Activez uniquement avec une valeur SAP KOH vérifiée.')
        ->assertSeeText('Conformité aromatique requise')
        ->assertSeeText('Active les informations allergènes et IFRA.')
        ->assertSeeText('Fonctions COSING vérifiées')
        ->assertSeeText('Fonctions officielles en lecture seule.')
        ->assertSeeText('Fonctions supplémentaires')
        ->assertSeeText('Générez un prompt pour le classement, la vérification des identifiants et de brèves notes professionnelles. Saisissez d’abord le nom de l’ingrédient ou son INCI.')
        ->assertSeeText('Ajouter l’ingrédient');
});

it('loads the saved ingredient status from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Glycérine',
        'category' => IngredientCategory::Other,
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
        ->assertSet('statusMessage', 'Modifications enregistrées.')
        ->assertDispatched(
            'app-notification',
            message: 'Modifications enregistrées.',
            type: 'success',
        );
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
