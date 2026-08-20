<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientFunctionSource;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Filament\Resources\Allergens\AllergenResource;
use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use App\Filament\Resources\IfraCertificates\Pages\CreateIfraCertificate;
use App\Filament\Resources\IfraCertificates\Pages\EditIfraCertificate;
use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\Ingredients\Schemas\IngredientForm;
use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use App\Filament\Resources\IngredientSubstanceEntries\IngredientSubstanceEntryResource;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\RegulatoryRegimeAllergens\RegulatoryRegimeAllergenResource;
use App\Filament\Resources\RegulatoryRegimes\RegulatoryRegimeResource;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\RegulatoryRegimeSubstanceRuleResource;
use App\Filament\Resources\Substances\SubstanceResource;
use App\Filament\Resources\SupportedLocales\Pages\CreateSupportedLocale;
use App\Filament\Resources\SupportedLocales\Pages\EditSupportedLocale;
use App\Filament\Resources\UserIngredients\Pages\ListUserIngredients;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Allergen;
use App\Models\IfraAmendment;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientFunction;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\IngredientTranslation;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\MediaStorage;
use Database\Seeders\PlanSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the entered display name when an admin creates an ingredient', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateIngredient::class)
        ->fillForm([
            'category' => IngredientCategory::AromaticMaterials->value,
            'subcategory' => IngredientSubcategory::EssentialOils->value,
            'current_version.display_name' => 'Grapefruit white',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ingredient = Ingredient::query()->whereLike('catalog_key', 'ADM-%')->latest('id')->firstOrFail();

    expect($ingredient->display_name)->toBe('Grapefruit white')
        ->and($ingredient->catalog_key)->toStartWith('ADM-')
        ->and(IngredientResource::getRecordTitle($ingredient))->toBe('Grapefruit white');
});

it('curates typed identity aliases and declared substances from the admin ingredient form', function (): void {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create(['code' => 'fr']);
    $substance = Substance::factory()->create(['name' => 'Linalool']);
    $this->actingAs($admin);

    Livewire::test(CreateIngredient::class)
        ->fillForm([
            'category' => IngredientCategory::AromaticMaterials->value,
            'subcategory' => IngredientSubcategory::EssentialOils->value,
            'current_version.display_name' => 'Lavender essential oil',
            'current_version.inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
            'current_version.cas_number' => '8000-28-0',
            'current_version.ec_number' => '289-995-2',
            'additional_identifiers' => [[
                'scheme' => 'unii',
                'value' => 'EXAMPLE123',
                'is_primary' => true,
            ]],
            'aliases' => [[
                'locale' => 'fr',
                'name' => 'Huile de lavande',
                'kind' => 'common',
            ]],
            'substance_entries' => [[
                'substance_id' => $substance->id,
                'concentration_percent' => 0.8,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ingredient = Ingredient::query()->where('display_name', 'Lavender essential oil')->firstOrFail();

    expect($ingredient->identifiers)->toHaveCount(3)
        ->and($ingredient->identifiers->where('scheme', 'unii')->value('value'))->toBe('EXAMPLE123')
        ->and($ingredient->aliases->first()->name)->toBe('Huile de lavande')
        ->and((float) $ingredient->substanceEntries->first()->concentration_percent)->toBe(0.8);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertSet('data.additional_identifiers', fn (array $state): bool => collect($state)->first()['scheme'] === 'unii')
        ->assertSet('data.aliases', fn (array $state): bool => collect($state)->first()['locale'] === 'fr')
        ->assertSet('data.substance_entries', fn (array $state): bool => (int) collect($state)->first()['substance_id'] === $substance->id);
});

it('generates an ingredient classification prompt from unsaved admin create state', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateIngredient::class)
        ->set('data.current_version.display_name', 'Sodium levulinate')
        ->set('data.current_version.inci_name', 'SODIUM LEVULINATE')
        ->set('data.current_version.cas_number', '19856-23-6')
        ->set('data.current_version.ec_number', '243-378-4')
        ->call('generateIngredientClassificationPrompt')
        ->assertDontSee('data-ingredient-classification-copy disabled', escape: false)
        ->assertSet('generatedIngredientClassificationPrompt', function (?string $prompt): bool {
            return is_string($prompt)
                && str_contains($prompt, '"name": "Sodium levulinate"')
                && str_contains($prompt, '"inci_name": "SODIUM LEVULINATE"')
                && str_contains($prompt, '"cas_number": "19856-23-6"')
                && str_contains($prompt, '"ec_number": "243-378-4"')
                && str_contains($prompt, 'Answer in: English (en).');
        });
});

it('shows the manual classification helper on admin ingredient create and edit forms', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    Livewire::test(CreateIngredient::class)
        ->assertSeeText('Classification helper')
        ->assertSeeText('Generate a prompt from the current unsaved identity fields. Paste it into an external AI assistant, then enter any useful result manually.')
        ->assertSeeText('Generate prompt')
        ->assertSeeText('Copy prompt')
        ->assertSee('data-ingredient-classification-copy', escape: false)
        ->assertSee('disabled', escape: false);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertSeeText('Classification helper')
        ->assertSeeText('Generate prompt')
        ->assertSeeText('Copy prompt')
        ->assertSee('data-ingredient-classification-copy', escape: false)
        ->assertSee('disabled', escape: false);
});

it('keeps ingredient create and edit form actions sticky', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    expect(Livewire::test(CreateIngredient::class)->instance()->areFormActionsSticky())
        ->toBeTrue()
        ->and(Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])->instance()->areFormActionsSticky())
        ->toBeTrue();
});

it('organizes specialist ingredient data into distinct Filament tabs', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertSeeText('Allergens')
        ->assertSeeText('Restricted substances')
        ->assertSeeText('IFRA');

    $lipid = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
    ]);

    Livewire::test(EditIngredient::class, ['record' => $lipid->public_id])
        ->assertSeeText('Soap chemistry')
        ->assertSeeText('Fatty acid profile');
});

it('organizes the complete ingredient editor into persistent top-level tabs with responsive field groups', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'is_soap_saponification_trusted' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    $form = Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->instance()
        ->form;
    $category = $form->getComponent('category');
    $displayName = $form->getComponent('current_version.display_name');
    $euDeclaration = $form->getComponent('market_labels.eu.declaration_name', withHidden: true);
    $euOverrideToggle = $form->getComponent('market_labels.eu.use_override');
    $guidance = $form->getComponent('info_markdown');
    $saponificationName = $form->getComponent('current_version.saponification_name');
    $allergens = $form->getComponent('allergen_entries');
    $substances = $form->getComponent('substance_entries');
    $ifraLimits = $form->getComponent('ifra.limits');
    $components = $form->getComponent('components');
    $additionalIdentifiers = $form->getComponent('additional_identifiers');
    $aliases = $form->getComponent('aliases');
    $translations = $form->getComponent('translations');
    $fattyAcids = $form->getComponent('fatty_acid_entries');
    $classificationSection = $category->getContainer()->getParentComponent();
    $generalTab = $classificationSection->getContainer()->getParentComponent();

    expect($generalTab)->toBeInstanceOf(Tab::class);

    /** @var Tab $generalTab */
    $editorTabs = $generalTab->getContainer()->getParentComponent();
    $tabLabels = collect($editorTabs->getChildSchema()->getComponents())
        ->map(fn (Tab $tab): string => (string) $tab->getLabel())
        ->values()
        ->all();
    $saponificationSection = $saponificationName->getContainer()->getParentComponent();

    expect($editorTabs)
        ->toBeInstanceOf(Tabs::class)
        ->and($editorTabs->getId())->toBe('ingredient-editor-tabs')
        ->and($editorTabs->getTabQueryStringKey())->toBe('ingredient-tab')
        ->and($editorTabs->getColumnSpan('default'))->toBe('full')
        ->and($tabLabels)->toBe([
            'General',
            'Market declarations',
            'Guidance & media',
            'Translations',
            'Soap chemistry',
            'Allergens',
            'Restricted substances',
            'IFRA',
            'Components',
        ])
        ->and($classificationSection)->toBe($displayName->getContainer()->getParentComponent())
        ->and($classificationSection->getColumns('default'))->toBe(1)
        ->and($classificationSection->getColumns('lg'))->toBe(2)
        ->and($euOverrideToggle)->not->toBeNull()
        ->and($euDeclaration->isVisible())->toBeFalse()
        ->and($generalTab)->not->toBe($euDeclaration->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($guidance->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($translations->getContainer()->getParentComponent())
        ->and($saponificationSection->getColumns('default'))->toBe(1)
        ->and($saponificationSection->getColumns('xl'))->toBe(3)
        ->and($generalTab)->not->toBe($saponificationSection->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($allergens->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($substances->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($ifraLimits->getContainer()->getParentComponent())
        ->and($generalTab)->not->toBe($components->getContainer()->getParentComponent())
        ->and($additionalIdentifiers->isCollapsed())->toBeTrue()
        ->and($aliases->isCollapsed())->toBeTrue()
        ->and($translations->isCollapsed())->toBeTrue()
        ->and($fattyAcids->isCollapsed())->toBeTrue();
});

it('saves current IFRA guidance from the ingredient specialist tab', function (): void {
    $admin = User::factory()->admin()->create();
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $category = IfraProductCategory::factory()->create([
        'code' => '5A',
        'name' => 'Body lotion',
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'subcategory' => IngredientSubcategory::EssentialOils,
        'requires_aromatic_compliance' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->set('data.ifra.reference_label', 'Supplier IFRA certificate')
        ->set('data.ifra.ifra_amendment_id', $amendment->id)
        ->set('data.ifra.peroxide_value', '2.5')
        ->set('data.ifra.source_notes', 'Reviewed supplier certificate.')
        ->set('data.ifra.limits', [[
            'ifra_product_category_id' => $category->id,
            'max_percentage' => '4.2',
            'restriction_note' => 'Finished product maximum.',
        ]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $certificate = $ingredient->ifraCertificates()
        ->with('limits')
        ->where('is_current', true)
        ->firstOrFail();

    expect($certificate->certificate_name)->toBe('Supplier IFRA certificate')
        ->and($certificate->ifra_amendment_id)->toBe($amendment->id)
        ->and((float) $certificate->peroxide_value)->toBe(2.5)
        ->and($certificate->limits)->toHaveCount(1)
        ->and((float) $certificate->limits->first()->max_percentage)->toBe(4.2)
        ->and($certificate->limits->first()->restriction_note)->toBe('Finished product maximum.');
});

it('links IFRA certificates to a known amendment and active category limits', function (): void {
    $admin = User::factory()->admin()->create();
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $activeCategory = IfraProductCategory::factory()->create([
        'code' => '9',
        'is_active' => true,
    ]);
    $inactiveCategory = IfraProductCategory::factory()->create([
        'code' => '12',
        'is_active' => false,
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => true,
    ]);
    $this->actingAs($admin);

    Livewire::test(CreateIfraCertificate::class)
        ->fillForm([
            'ingredient_id' => $ingredient->id,
            'certificate_name' => 'Supplier IFRA 51',
            'ifra_amendment_id' => $amendment->id,
            'is_current' => true,
            'limits' => [[
                'ifra_product_category_id' => $activeCategory->id,
                'max_percentage' => '4.2',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $certificate = IfraCertificate::query()->where('certificate_name', 'Supplier IFRA 51')->firstOrFail();

    expect($certificate->ifraAmendment->is($amendment))->toBeTrue()
        ->and($certificate->limits)->toHaveCount(1)
        ->and($certificate->limits->first()->ifra_product_category_id)->toBe($activeCategory->id);

    $categoryField = Livewire::test(CreateIfraCertificate::class)
        ->instance()
        ->form
        ->getComponent('limits')
        ->getChildSchema()
        ->getComponent('ifra_product_category_id');

    expect($categoryField->getOptions())->toHaveKey($activeCategory->id)
        ->not->toHaveKey($inactiveCategory->id);
});

it('shows a mismatched source amendment label without making it editable', function (): void {
    $admin = User::factory()->admin()->create();
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $certificate = IfraCertificate::factory()->create([
        'ifra_amendment_id' => $amendment->id,
        'source_amendment_label' => 'Supplier marked this as 50th',
    ]);
    $this->actingAs($admin);

    $component = Livewire::test(EditIfraCertificate::class, ['record' => $certificate->id]);
    $sourceLabel = $component->instance()->form->getComponent('source_amendment_label');

    $component->assertSee('Supplier marked this as 50th');
    expect($sourceLabel->isVisible())->toBeTrue()
        ->and($sourceLabel->getContent())->toBe('Source amendment label: Supplier marked this as 50th');
});

it('generates an ingredient classification prompt from unsaved admin edit state', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'display_name' => 'Original name',
        'inci_name' => 'ORIGINAL INCI',
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->set('data.current_version.display_name', 'Edited unsaved name')
        ->set('data.current_version.inci_name', 'EDITED UNSAVED INCI')
        ->call('generateIngredientClassificationPrompt')
        ->assertSet('generatedIngredientClassificationPrompt', function (?string $prompt): bool {
            return is_string($prompt)
                && str_contains($prompt, '"name": "Edited unsaved name"')
                && str_contains($prompt, '"inci_name": "EDITED UNSAVED INCI"')
                && ! str_contains($prompt, '"name": "Original name"');
        });

    expect($ingredient->refresh()->display_name)->toBe('Original name');
});

it('requires an identity before generating an admin ingredient classification prompt', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateIngredient::class)
        ->call('generateIngredientClassificationPrompt')
        ->assertNotified('Enter an ingredient name or INCI before generating the prompt.')
        ->assertSet('generatedIngredientClassificationPrompt', null);
});

it('keeps original upload names in the admin ingredient form state', function () {
    config(['media.disk' => 'local']);
    Storage::fake(MediaStorage::publicDisk());

    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'featured_image_path' => 'ingredients/featured-images/01JQ4RANDOM.webp',
        'icon_image_path' => 'ingredients/icons/01JQ5RANDOM.webp',
    ]);
    $this->actingAs($admin);

    $featuredField = Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->instance()
        ->form
        ->getComponent('featured_image_path');
    $iconField = Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->instance()
        ->form
        ->getComponent('icon_image_path');
    Storage::disk($featuredField->getDiskName())->put($ingredient->featured_image_path, 'public-image');
    Storage::disk($iconField->getDiskName())->put($ingredient->icon_image_path, 'public-image');
    $featuredField->rawState([$ingredient->featured_image_path => $ingredient->featured_image_path]);
    $iconField->rawState([$ingredient->icon_image_path => $ingredient->icon_image_path]);

    expect($featuredField)->toBeInstanceOf(FileUpload::class)
        ->and($featuredField->getFileNamesStatePath())->toEndWith('featured_image_original_name')
        ->and(array_values($featuredField->getUploadedFiles())[0]['name'])->toBe('Current image')
        ->and(array_values($featuredField->getUploadedFiles())[0]['name'])->not->toBe(basename($ingredient->featured_image_path))
        ->and($iconField)->toBeInstanceOf(FileUpload::class)
        ->and($iconField->getFileNamesStatePath())->toEndWith('icon_image_original_name')
        ->and(array_values($iconField->getUploadedFiles())[0]['name'])->toBe('Current image')
        ->and(array_values($iconField->getUploadedFiles())[0]['name'])->not->toBe(basename($ingredient->icon_image_path));
});

it('creates a language from the Laravel Lang catalogue', function () {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create(['code' => 'en']);

    $this->actingAs($admin);

    Livewire::test(CreateSupportedLocale::class)
        ->fillForm([
            'catalog_locale' => 'fr',
            'sort_order' => 20,
            'is_active' => false,
            'is_default' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(SupportedLocale::class, [
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'number_locale' => 'fr_FR',
        'text_direction' => 'ltr',
        'is_active' => false,
    ]);
});

it('keeps Laravel Lang identity metadata read only when editing a language', function () {
    $admin = User::factory()->admin()->create();
    $locale = SupportedLocale::factory()->create([
        'code' => 'es',
        'name' => 'Spanish',
        'native_name' => 'Español',
        'number_locale' => 'es_ES',
        'text_direction' => 'ltr',
        'sort_order' => 30,
        'is_active' => false,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditSupportedLocale::class, ['record' => $locale->id])
        ->fillForm([
            'code' => 'xx',
            'name' => 'Changed',
            'native_name' => 'Changed',
            'number_locale' => 'xx_XX',
            'text_direction' => 'rtl',
            'sort_order' => 40,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($locale->refresh()->only([
        'code',
        'name',
        'native_name',
        'number_locale',
        'text_direction',
        'sort_order',
        'is_active',
    ]))->toBe([
        'code' => 'es',
        'name' => 'Spanish',
        'native_name' => 'Español',
        'number_locale' => 'es_ES',
        'text_direction' => 'ltr',
        'sort_order' => 40,
        'is_active' => true,
    ]);
});

it('renders the catalog list resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'is_soap_saponification_trusted' => true,
        'catalog_key' => 'OB1',
    ]);

    IngredientSapProfile::factory()
        ->for($ingredient, 'ingredient')
        ->create([
            'koh_sap_value' => 0.188,
            'iodine_value' => 84.500,
        ]);

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil')
        ->assertSee('Oils, butters &amp; fats', false);

    $this->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Soap chemistry')
        ->assertSee('Fatty acid profile')
        ->assertSee('Ingredient guidance')
        ->assertSee('COSING functions')
        ->assertSee('Verified COSING functions retain their source');

    $this->get(IngredientSapProfileResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil');
});

it('renders all assigned ingredient functions in one editable Filament multi select', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Zea mays starch',
    ]);
    $functions = collect(['abrasive' => 'Abrasive', 'absorbent' => 'Absorbent'])
        ->map(fn (string $name, string $key): IngredientFunction => IngredientFunction::factory()->create([
            'key' => $key,
            'name' => $name,
        ]));
    $ingredient->functions()->attach($functions->pluck('id'), [
        'source' => IngredientFunctionSource::CosIng->value,
    ]);
    $this->actingAs($admin);

    $livewire = Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id]);
    $component = $livewire->instance()->form->getComponent('reviewed_function_ids');

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->isMultiple())->toBeTrue()
        ->and($component->getState())->toEqualCanonicalizing($functions->pluck('id')->all());

    $livewire
        ->set('data.reviewed_function_ids', [$functions->first()->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($ingredient->fresh()->functions()->pluck('ingredient_functions.id')->all())
        ->toBe([$functions->first()->id]);
});

it('lists user ingredients anonymously in a separate read only admin resource', function () {
    $admin = User::factory()->admin()->create();
    $ingredientOwner = User::factory()->create([
        'name' => 'Confidential Maker',
        'email' => 'private-maker@example.test',
    ]);
    $platformIngredient = Ingredient::factory()->create([
        'display_name' => 'Platform Olive Oil',
    ]);
    $userIngredient = Ingredient::factory()->create([
        'display_name' => 'Maker Apricot Oil',
        'inci_name' => null,
        'notes' => 'Private catalogue note',
        'owner_type' => OwnerType::User,
        'owner_id' => $ingredientOwner->id,
        'visibility' => Visibility::Private,
    ]);
    $completeUserIngredient = Ingredient::factory()->create([
        'display_name' => 'Maker Cocoa Butter',
        'inci_name' => 'THEOBROMA CACAO SEED BUTTER',
        'owner_type' => OwnerType::User,
        'owner_id' => $ingredientOwner->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($admin)
        ->get('/admin/user-ingredients')
        ->assertSuccessful()
        ->assertSeeText('User Ingredients')
        ->assertSeeText($userIngredient->display_name)
        ->assertSeeText('Not provided')
        ->assertSeeText('Private catalogue note')
        ->assertDontSeeText($platformIngredient->display_name)
        ->assertDontSeeText($ingredientOwner->name)
        ->assertDontSeeText($ingredientOwner->email)
        ->assertDontSeeText('Formula');

    Livewire::test(ListUserIngredients::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$userIngredient, $completeUserIngredient])
        ->assertCanNotSeeTableRecords([$platformIngredient])
        ->searchTable('Maker Apricot')
        ->assertCanSeeTableRecords([$userIngredient])
        ->assertCanNotSeeTableRecords([$completeUserIngredient])
        ->searchTable('')
        ->filterTable('missing_inci')
        ->assertCanSeeTableRecords([$userIngredient])
        ->assertCanNotSeeTableRecords([$completeUserIngredient])
        ->assertActionDoesNotExist(TestAction::make('view')->table($userIngredient))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($userIngredient))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($userIngredient));
});

it('renders registered platform ingredient translation locales in Filament', function () {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create([
        'code' => 'en',
        'name' => 'English',
        'native_name' => 'English',
        'is_default' => true,
        'sort_order' => 10,
    ]);
    SupportedLocale::factory()->create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'is_active' => false,
        'sort_order' => 20,
    ]);
    SupportedLocale::factory()->create([
        'code' => 'de',
        'name' => 'German',
        'native_name' => 'Deutsch',
        'is_active' => false,
        'sort_order' => 30,
    ]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'info_markdown' => 'English guidance',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
        ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'de',
            'display_name' => 'Olivenöl',
        ]);

    $this->actingAs($admin)
        ->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertSuccessful()
        ->assertSeeText('Translate the public ingredient name, saponification name, and guidance.')
        ->assertSeeText('French')
        ->assertSeeText('German')
        ->assertSeeText('Olive Oil')
        ->assertSeeText('English guidance');
});

it('lets admins save platform ingredient translations in Filament', function () {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create(['code' => 'fr', 'name' => 'French']);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'display_name' => 'Olive Oil',
        'saponification_name' => 'Olive',
        'is_soap_saponification_trusted' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->fillForm([
            'current_version.saponification_name' => 'Olive fruit',
            'translations' => [[
                'locale' => 'fr',
                'display_name' => 'Huile d’olive',
                'saponification_name' => 'Olive',
                'info_markdown' => 'Conseils en français',
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(IngredientTranslation::query()
        ->whereBelongsTo($ingredient)
        ->where('locale', 'fr')
        ->firstOrFail()
        ->only(['display_name', 'saponification_name', 'info_markdown']))
        ->toBe([
            'display_name' => 'Huile d’olive',
            'saponification_name' => 'Olive',
            'info_markdown' => 'Conseils en français',
        ]);

    expect($ingredient->refresh()->saponification_name)->toBe('Olive fruit');
});

it('validates ingredient translations before saving canonical ingredient data', function () {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create(['code' => 'fr', 'name' => 'French']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->fillForm([
            'current_version.display_name' => 'Changed English Name',
            'translations' => [[
                'locale' => 'fr',
                'display_name' => ' ',
                'info_markdown' => null,
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($ingredient->refresh()->display_name)->toBe('Olive Oil');
});

it('lets admins delete an unused platform ingredient from its edit page', function () {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionVisible('delete')
        ->callAction('delete')
        ->assertNotified('Ingredient deleted');

    $this->assertModelMissing($ingredient);
});

it('blocks deletion of a used platform ingredient and recommends deactivation', function () {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $recipeItem = RecipeItem::factory()->create([
        'ingredient_id' => $ingredient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->callAction('delete')
        ->assertActionHalted('delete')
        ->assertNotified('Ingredient was not deleted');

    $this->assertModelExists($ingredient);
    $this->assertModelExists($recipeItem);
});

it('blocks admin deletion when a platform ingredient is used only in formula history', function () {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $historicalVersion = RecipeVersion::factory()->create([
        'is_current' => false,
        'saved_at' => now(),
    ]);
    $recipeItem = RecipeItem::factory()->create([
        'recipe_version_id' => $historicalVersion->id,
        'ingredient_id' => $ingredient->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->callAction('delete')
        ->assertActionHalted('delete')
        ->assertNotified('Ingredient was not deleted');

    $this->assertModelExists($ingredient);
    $this->assertModelExists($recipeItem);
});

it('does not expose private user ingredients through the editable platform resource', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($admin)
        ->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertNotFound();
});

it('does not expose private ingredients through the platform translation editor', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    SupportedLocale::factory()->create(['code' => 'fr', 'name' => 'French']);
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($admin)
        ->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertNotFound();
});

it('keeps composite component ingredient options current within the request', function () {
    $oliveOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'catalog_key' => 'OIL-OLIVE',
        'is_active' => true,
    ]);

    $method = new ReflectionMethod(IngredientForm::class, 'componentIngredientOptions');
    $method->setAccessible(true);

    $firstOptions = $method->invoke(null, null);

    expect($firstOptions)->toHaveKey($oliveOil->id);

    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'catalog_key' => 'OIL-COCONUT',
        'is_active' => true,
    ]);

    $privateIngredient = Ingredient::factory()->create([
        'display_name' => 'Private Maker Blend',
        'owner_type' => OwnerType::User,
        'owner_id' => User::factory()->create()->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);

    $secondOptions = $method->invoke(null, null);

    expect($secondOptions)
        ->toHaveKey($coconutOil->id)
        ->not->toHaveKey($privateIngredient->id);
});

it('offers a read-only view action on the ingredient admin table', function () {
    $user = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->assertActionExists(TestAction::make('view')->table($ingredient))
        ->assertActionExists(TestAction::make('edit')->table($ingredient));
});

it('does not render the classification helper in the ingredient view action', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->mountAction(TestAction::make('view')->table($ingredient))
        ->assertMountedActionModalDontSee('Classification helper');

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->mountAction(TestAction::make('edit')->table($ingredient))
        ->assertMountedActionModalDontSee('Classification helper');
});

it('keeps user ingredients out of the editable platform ingredient catalog', function () {
    $admin = User::factory()->admin()->create();
    $ingredientOwner = User::factory()->create();
    $platformIngredient = Ingredient::factory()->create([
        'display_name' => 'Platform Castor Oil',
    ]);
    $userIngredient = Ingredient::factory()->create([
        'display_name' => 'Private Castor Blend',
        'owner_type' => OwnerType::User,
        'owner_id' => $ingredientOwner->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$platformIngredient])
        ->assertCanNotSeeTableRecords([$userIngredient]);
});

it('renders the catalog create forms in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Ingredient category')
        ->assertSee('Identity')
        ->assertSee('Guidance &amp; media', false)
        ->assertSee('Ingredient guidance')
        ->assertSee('Ingredient image')
        ->assertSee('COSING functions')
        ->assertSee('Composite Components')
        ->assertDontSee('Internal Metadata');

    $this->get(IngredientSapProfileResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Saponification Data')
        ->assertSee('Iodine')
        ->assertSee('INS');
});

it('renders the plan limits resource in the admin panel', function () {
    $user = User::factory()->admin()->create();
    $this->seed(PlanSeeder::class);
    $plan = Plan::query()->where('slug', 'free-beta')->firstOrFail();

    $this->actingAs($user);

    $this->get(PlanResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Free beta')
        ->assertSee('15')
        ->assertSee('20')
        ->assertSee('30')
        ->assertSee('Ingredient lines per formula');

    $this->get(PlanResource::getUrl('edit', ['record' => $plan], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Plan')
        ->assertSee('Limits')
        ->assertSee('Saved recipes')
        ->assertSee('Private ingredients')
        ->assertSee('Ingredient lines per formula');
});

it('adds formula line defaults to new billable plans without overwriting edits', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreatePlan::class)
        ->fillForm([
            'name' => 'Growth',
            'slug' => 'growth',
            'paddle_price_id' => 'pri_growth',
            'is_active' => true,
            'limits' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::query()->where('slug', 'growth')->firstOrFail();

    expect($plan->limits()->where('key', 'formula_items_per_recipe')->value('value'))->toBe(50);

    $plan->limits()->where('key', 'formula_items_per_recipe')->update(['value' => 37]);

    Livewire::test(EditPlan::class, ['record' => $plan->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->fresh()->limits()->where('key', 'formula_items_per_recipe')->value('value'))->toBe(37);
});

it('renders the user management resource with plan subscription and usage context', function () {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create([
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
    ]);
    $plan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create([
            'name' => 'Growth',
            'slug' => 'growth',
        ]);

    $customer->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'source' => 'paddle',
        'starts_at' => now(),
    ]);

    $subscription = $customer->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_admin_test',
        'status' => 'active',
    ]);

    $subscription->items()->create([
        'product_id' => 'pro_growth',
        'price_id' => 'pri_growth_monthly',
        'status' => 'active',
        'quantity' => 1,
    ]);

    Recipe::factory()
        ->count(2)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $customer->id,
            'visibility' => Visibility::Private,
        ]);

    Ingredient::factory()
        ->count(3)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $customer->id,
            'visibility' => Visibility::Private,
        ]);

    ProductionBatch::factory()->create([
        'user_id' => $customer->id,
    ]);

    $this->actingAs($admin);

    $this->get(UserResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Marie Maker')
        ->assertSee('marie@example.com')
        ->assertSee('Growth')
        ->assertSee('Active')
        ->assertSee('2')
        ->assertSee('3')
        ->assertSee('1');

    $this->get(UserResource::getUrl('edit', ['record' => $customer], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('User')
        ->assertSee('Marie Maker')
        ->assertSee('marie@example.com')
        ->assertSee('Current access')
        ->assertSee('Growth')
        ->assertSee('sub_admin_test')
        ->assertSee('Saved recipes')
        ->assertSee('Private ingredients')
        ->assertSee('Production batches');
});

it('lets admins update user identity and admin access', function () {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create([
        'name' => 'Original Maker',
        'email' => 'original@example.com',
        'is_admin' => false,
    ]);
    $originalPassword = $customer->password;

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $customer->id])
        ->fillForm([
            'name' => 'Updated Maker',
            'email' => 'updated@example.com',
            'email_verified_at' => $customer->email_verified_at,
            'is_admin' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh())
        ->name->toBe('Updated Maker')
        ->email->toBe('updated@example.com')
        ->is_admin->toBeTrue()
        ->password->toBe($originalPassword);
});

it('lets admins create and delete users', function () {
    $admin = User::factory()->admin()->create();
    $freePlan = Plan::factory()->create([
        'name' => 'Free beta',
        'slug' => 'free-beta',
        'is_default' => true,
    ]);
    $customer = User::factory()->create([
        'email' => 'delete-me@example.com',
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->assertSee(__('auth.password_requirements'))
        ->fillForm([
            'name' => 'Created Maker',
            'email' => 'created@example.com',
            'email_verified_at' => now(),
            'is_admin' => false,
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdUser = User::query()->where('email', 'created@example.com')->firstOrFail();

    expect($createdUser->name)->toBe('Created Maker')
        ->and(Hash::check('NewSecurePass1!', $createdUser->password))->toBeTrue()
        ->and($createdUser->entitlements()->where('plan_id', $freePlan->id)->where('status', 'active')->exists())->toBeTrue();

    $this->post(route('logout'));
    $this->post(route('login'), [
        'email' => 'created@example.com',
        'password' => 'NewSecurePass1!',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($createdUser);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $customer->id])
        ->callAction(DeleteAction::class);

    expect(User::query()->where('email', 'delete-me@example.com')->exists())->toBeFalse();
});

it('lets admins reset user passwords from the user form', function () {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create([
        'name' => 'Password Reset Target',
        'email' => 'reset-target@example.com',
        'password' => 'old-password',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $customer->id])
        ->fillForm([
            'name' => $customer->name,
            'email' => $customer->email,
            'email_verified_at' => $customer->email_verified_at,
            'is_admin' => false,
            'password' => 'FreshSecurePass1!',
            'password_confirmation' => 'FreshSecurePass1!',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('FreshSecurePass1!', $customer->refresh()->password))->toBeTrue();
});

it('renders the compliance resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'catalog_key' => 'EO1',
    ]);

    $allergen = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    IngredientAllergenEntry::factory()
        ->for($ingredient, 'ingredient')
        ->for($allergen, 'allergen')
        ->create([
            'concentration_percent' => 0.85000,
        ]);

    $regulatoryRegime = RegulatoryRegime::factory()->create([
        'code' => 'eu',
        'name' => 'EU regime',
        'version_label' => 'Full 82 fragrance allergens',
    ]);

    RegulatoryRegimeAllergen::factory()
        ->for($regulatoryRegime, 'regulatoryRegime')
        ->for($allergen, 'allergen')
        ->create([
            'declaration_label' => 'LINALOOL',
        ]);
    $substance = Substance::factory()
        ->for($allergen, 'allergen')
        ->create([
            'name' => 'Linalool',
            'entity_type' => 'constituent',
        ]);

    IngredientSubstanceEntry::factory()
        ->for($ingredient, 'ingredient')
        ->for($substance, 'substance')
        ->create([
            'concentration_percent' => 0.85000,
            'concentration_source' => 'supplier',
        ]);

    RegulatoryRegimeSubstanceRule::factory()
        ->for($regulatoryRegime, 'regulatoryRegime')
        ->for($substance, 'substance')
        ->create([
            'rule_type' => 'watch',
        ]);

    $ifraProductCategory = IfraProductCategory::factory()->create([
        'code' => '9',
        'name' => 'Category 9',
    ]);

    $productFamily = ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
    ]);

    $ifraProductCategory->productFamilyMappings()->create([
        'product_family_id' => $productFamily->id,
        'is_default' => true,
        'sort_order' => 1,
    ]);

    $ifraCertificate = IfraCertificate::factory()
        ->for($ingredient, 'ingredient')
        ->create([
            'certificate_name' => 'Lavender High Alt IFRA',
            'ifra_amendment' => '51',
        ]);

    IfraCertificateLimit::factory()
        ->for($ifraCertificate, 'certificate')
        ->for($ifraProductCategory, 'ifraProductCategory')
        ->create([
            'max_percentage' => 5.00000,
        ]);

    $this->actingAs($user);

    $this->get(AllergenResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('LINALOOL');

    $this->get(IngredientAllergenEntryResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Lavender Essential Oil')
        ->assertSee('LINALOOL');

    $this->get(RegulatoryRegimeResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('EU regime')
        ->assertSee('Full 82 fragrance allergens');

    $this->get(RegulatoryRegimeAllergenResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('EU regime')
        ->assertSee('LINALOOL');

    $this->get(SubstanceResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Linalool')
        ->assertSee('constituent');

    $this->get(IngredientSubstanceEntryResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Lavender Essential Oil')
        ->assertSee('Linalool');

    $this->get(RegulatoryRegimeSubstanceRuleResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('EU regime')
        ->assertSee('Linalool');

    $this->get(IfraProductCategoryResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Category 9');

    $this->get(IfraCertificateResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Lavender High Alt IFRA')
        ->assertSee('Lavender Essential Oil');

    $this->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Lavender Essential Oil')
        ->assertSee('Identity')
        ->assertSee('Allergens')
        ->assertSee('Guidance &amp; media', false)
        ->assertSee('Composite Components');
});

it('renders the compliance create forms in the admin panel', function () {
    $user = User::factory()->admin()->create();
    ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
    ]);

    $this->actingAs($user);

    $this->get(AllergenResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('INCI label name')
        ->assertSee('Source Traceability');

    $this->get(IngredientAllergenEntryResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Allergen Composition')
        ->assertSee('Concentration');

    $this->get(RegulatoryRegimeResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Regime Identity')
        ->assertSee('Effective Window')
        ->assertSee('Source Traceability');

    $this->get(RegulatoryRegimeAllergenResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Regime Rule')
        ->assertSee('Thresholds')
        ->assertSee('Grouping And Effective Window');

    $this->get(SubstanceResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Substance Catalog')
        ->assertSee('Allergen link');

    $this->get(IngredientSubstanceEntryResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Ingredient Substance Composition')
        ->assertSee('Concentration');

    $this->get(RegulatoryRegimeSubstanceRuleResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Regime Substance Rule')
        ->assertSee('Exposure Limits');

    $this->get(IfraProductCategoryResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Category Identity')
        ->assertSee('Short label')
        ->assertSee('Full description')
        ->assertSee('Product Family Mapping');

    $this->get(IfraCertificateResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Current IFRA Guidance')
        ->assertSee('Peroxide value')
        ->assertSee('Optional Reference Metadata')
        ->assertSee('Category Limits');
});

it('blocks non-admin users from the admin panel resources', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl(panel: 'admin'))
        ->assertForbidden();

    $this->get(IfraCertificateResource::getUrl(panel: 'admin'))
        ->assertForbidden();

    $this->get(RegulatoryRegimeResource::getUrl(panel: 'admin'))
        ->assertForbidden();

    $this->get(UserResource::getUrl(panel: 'admin'))
        ->assertForbidden();
});
