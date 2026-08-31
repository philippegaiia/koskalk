<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UserIngredientAuthoringService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('shows localized platform content while preserving authored workspace names', function (): void {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    $user = User::factory()->create(['locale' => 'fr']);
    Workspace::factory()->for($user, 'owner')->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Coconut oil',
        'info_markdown' => 'English guidance',
        'owner_type' => null,
        'owner_id' => null,
    ]);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $platform->id,
        'locale' => 'fr',
        'display_name' => 'Huile de coco',
        'info_markdown' => 'Conseils en français',
    ]);

    App::setLocale('fr');
    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSet('data.name', 'Huile de coco')
        ->call('startWorkspaceGuidanceCustomization')
        ->assertSet('isEditingWorkspaceGuidance', true)
        ->tap(fn ($test) => expect($test->instance()->workspaceGuidanceForm->getState()['html'])->toBe('<p>Conseils en français</p>'))
        ->assertSeeText('Huile de coco')
        ->assertDontSeeText('Coconut oil');
});

it('uses the approved task-focused copy on the add ingredient page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('data-ingredient-save-bar')
        ->assertSeeHtml('sk-workflow-action-bar')
        ->assertSeeHtml('type="submit"')
        ->assertSeeHtml('class="sk-btn sk-btn-ghost"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary"')
        ->assertSeeText('Add ingredient')
        ->assertSeeText('Add an ingredient to your library.')
        ->assertSeeText('Start with the ingredient name and INCI. Add classification, reference information, and compliance details only when relevant.')
        ->assertSeeText('Overview')
        ->assertSeeText('Documents')
        ->assertSeeText('Ingredient identity')
        ->assertSeeText('Start with the name used in your workspace and the INCI when known.')
        ->assertSeeText('Ingredient type')
        ->assertSeeText('Single ingredient')
        ->assertSeeText('Blend')
        ->assertSeeText('Choose Blend when this ingredient is made from several ingredients.')
        ->assertSeeText('Classification')
        ->assertSeeText('Reference identifiers')
        ->assertDontSeeText('Certified organic')
        ->assertDontSeeText('Verified COSING functions')
        ->assertSeeText('Functions used in your workspace')
        ->assertSeeText('AI research helper')
        ->assertSeeText('Prepare an ingredient research prompt')
        ->assertSeeText('Generate a prompt to research classification, identifiers, COSING functions, and concise professional notes. It will not change this form.')
        ->assertSeeText('Generate prompt')
        ->assertSeeText('Copy prompt')
        ->assertSee('data-classification-prompt-copy', escape: false)
        ->assertSee('disabled', escape: false)
        ->assertDontSeeText('Classify the cosmetic or soapmaking ingredient below.')
        ->assertDontSeeText('"name": null')
        ->assertSeeText('Documents and media')
        ->assertSeeText('Source notes')
        ->assertSeeText('Add supplier or source details that may help identify and classify this ingredient.')
        ->assertSeeText('Formulation notes')
        ->assertSeeText('Add formulation guidance or other practical notes for your workspace.')
        ->assertDontSeeText('Identifiers and functions')
        ->assertDontSeeText('Trusted for soap saponification')
        ->assertSeeText('Add ingredient')
        ->assertDontSeeText('Create a personal ingredient')
        ->assertDontSeeText('Catalog item type')
        ->assertDontSeeText('Optional workspace context')
        ->assertDontSeeText('Create ingredient');

    $html = $response->getContent();

    expect(strpos($html, 'classification-prompt-title'))
        ->toBeLessThan(strpos($html, 'data-ingredient-classification-section'))
        ->and(strpos($html, 'data-ingredient-classification-section'))
        ->toBeLessThan(strpos($html, 'data-ingredient-identity-section'));
});

it('keeps save and cancel actions sticky when editing a workspace ingredient', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient))
        ->assertSuccessful()
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('data-ingredient-save-bar')
        ->assertSeeText('Cancel')
        ->assertSeeText('Save changes');
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

it('does not let a manually created ingredient expose soap chemistry', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->assertDontSeeText('Trusted for soap saponification')
        ->set('data.is_soap_saponification_trusted', true)
        ->assertDontSeeText('Soap calculation data inherited from Soapkraft.')
        ->assertDontSeeText('Saponification values');
});

it('shows inherited soap chemistry for a duplicated platform oil', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Platform olive oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $copy])
        ->assertDontSeeText('Trusted for soap saponification')
        ->assertSeeText('Soap calculation data inherited from Soapkraft.')
        ->assertSeeText('Saponification values')
        ->assertSeeText('Add the values used to calculate this oil in soap formulas.')
        ->assertSeeText('Allowed KOH SAP range');
});

it('loads ingredient editor interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.create.page_title' => 'Ajouter un ingrédient',
        'editor.create.heading' => 'Ajoutez un ingrédient à votre bibliothèque.',
        'editor.create.intro' => 'Commencez par les informations essentielles.',
        'editor.tabs.details' => 'Vue d’ensemble',
        'editor.tabs.documents' => 'Documents',
        'editor.details.section' => 'Identité de l’ingrédient',
        'editor.details.type.label' => 'Type d’ingrédient',
        'editor.details.type.single' => 'Ingrédient simple',
        'editor.details.type.blend' => 'Mélange',
        'editor.classification.section' => 'Classification',
        'editor.identity.section' => 'Identifiants de référence',
        'editor.details.aromatic_compliance' => 'Conformité aromatique requise',
        'editor.details.aromatic_compliance_helper' => 'Active les informations allergènes et IFRA.',
        'editor.supplier.verified_functions' => 'Fonctions COSING vérifiées',
        'editor.supplier.none_verified' => 'Aucune fonction vérifiée',
        'editor.supplier.verified_functions_helper' => 'Fonctions officielles en lecture seule.',
        'editor.supplier.additional_functions' => 'Fonctions utilisées dans votre espace de travail',
        'editor.classification_prompt.eyebrow' => 'Assistant de recherche IA',
        'editor.classification_prompt.description' => 'Générez un prompt pour rechercher la classification, les identifiants, les fonctions COSING et de brèves notes professionnelles. Il ne modifiera pas ce formulaire.',
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
        ->assertSeeText('Vue d’ensemble')
        ->assertSeeText('Documents')
        ->assertSeeText('Identité de l’ingrédient')
        ->assertSeeText('Type d’ingrédient')
        ->assertSeeText('Ingrédient simple')
        ->assertSeeText('Mélange')
        ->assertSeeText('Classification')
        ->assertSeeText('Identifiants de référence')
        ->assertSeeText('Conformité aromatique requise')
        ->assertSeeText('Active les informations allergènes et IFRA.')
        ->assertDontSeeText('Fonctions COSING vérifiées')
        ->assertDontSeeText('Fonctions officielles en lecture seule.')
        ->assertSeeText('Fonctions utilisées dans votre espace de travail')
        ->assertSeeText('Assistant de recherche IA')
        ->assertSeeText('Générez un prompt pour rechercher la classification, les identifiants, les fonctions COSING et de brèves notes professionnelles. Il ne modifiera pas ce formulaire.')
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

it('shows translated validation feedback and a generic translated save alert', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.status.invalid' => 'Vérifiez les champs signalés.',
        'editor.validation.blend_required' => 'Ajoutez au moins un ingrédient pour enregistrer ce mélange.',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    App::setLocale('fr');
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Mélange test')
        ->set('data.category', IngredientCategory::Other->value)
        ->set('data.ingredient_structure', 'blend')
        ->call('save')
        ->assertHasErrors(['data.components'])
        ->assertSeeText('Ajoutez au moins un ingrédient pour enregistrer ce mélange.')
        ->assertDispatched(
            'app-notification',
            message: 'Vérifiez les champs signalés.',
            type: 'error',
        );
});

it('routes workspace ingredient authoring errors through translation keys', function () {
    $authoringSource = file_get_contents(app_path('Services/UserIngredientAuthoringService.php'));
    $dataEntrySource = file_get_contents(app_path('Services/IngredientDataEntryService.php'));

    foreach ([
        'This private ingredient cannot be edited from the public app.',
        'Only platform ingredients can be duplicated.',
        'Choose a subcategory belonging to the selected ingredient category.',
        'Add at least one component to save a blend.',
        'KOH SAP value is required for duplicated carrier oils trusted for soap calculation.',
        'Fatty acid percentages must total between 80% and 100%.',
        'Allergen concentration must not be negative.',
        'Peroxide value must not be negative.',
        'Max concentration must not exceed 100%.',
    ] as $hardCodedMessage) {
        expect($authoringSource)->not->toContain($hardCodedMessage);
    }

    foreach ([
        'Composite components must reference existing catalog ingredients.',
        'A blend can contain at most 20 components.',
        'Composite ingredient percentages must total 100%.',
        'An ingredient cannot include itself as a component.',
        'This component would create a circular ingredient composition.',
    ] as $hardCodedMessage) {
        expect($dataEntrySource)->not->toContain($hardCodedMessage);
    }
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
        'editor.tabs.documents',
        'editor.tabs.soap_chemistry',
        'editor.tabs.compliance',
        'editor.details.section',
        'editor.details.notes_helper',
        'editor.classification.section',
        'editor.identity.section',
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
        'editor.validation.private_edit_forbidden',
        'editor.validation.duplicate_platform_only',
        'editor.validation.duplicate_soap_profile_required',
        'editor.validation.subcategory_mismatch',
        'editor.validation.blend_required',
        'editor.validation.blend_component_unavailable',
        'editor.validation.soap_koh_required',
        'editor.validation.soap_koh_tolerance',
        'editor.validation.fatty_acid_total',
        'editor.validation.fatty_acid_range',
        'editor.validation.allergen_negative',
        'editor.validation.allergen_maximum',
        'editor.validation.peroxide_negative',
        'editor.validation.ifra_maximum_negative',
        'editor.validation.ifra_maximum',
        'editor.validation.component_reference_required',
        'editor.validation.composition_total',
        'editor.validation.composition_self',
        'editor.validation.composition_cycle',
        'editor.validation.private_ingredient_limit',
        'editor.identity.identifier_schemes.inchikey',
        'editor.identity.identifier_schemes.pubchem_cid',
    ]);
});

it('presents primary identifiers separately from supported additional schemes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Primary CAS number')
        ->assertSeeText('Primary EC / EINECS number')
        ->assertSeeText('InChIKey')
        ->assertSeeText('PubChem CID');
});
