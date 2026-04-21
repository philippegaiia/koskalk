<?php

use App\Filament\Resources\Allergens\AllergenResource;
use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\Ingredients\Schemas\IngredientForm;
use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the catalog list resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'is_potentially_saponifiable' => true,
        'source_key' => 'OB1',
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
        ->assertSee('Carrier Oil');

    $this->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Soap Chemistry')
        ->assertSee('Fatty acid profile')
        ->assertSee('Ingredient guidance')
        ->assertSee('EU / COSING functions');

    $this->get(IngredientSapProfileResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil');
});

it('keeps composite component ingredient options current within the request', function () {
    $oliveOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'source_key' => 'OIL-OLIVE',
        'is_active' => true,
    ]);

    $method = new ReflectionMethod(IngredientForm::class, 'componentIngredientOptions');
    $method->setAccessible(true);

    $firstOptions = $method->invoke(null, null);

    expect($firstOptions)->toHaveKey($oliveOil->id);

    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Coconut Oil',
        'source_key' => 'OIL-COCONUT',
        'is_active' => true,
    ]);

    $secondOptions = $method->invoke(null, null);

    expect($secondOptions)->toHaveKey($coconutOil->id);
});

it('offers a read-only view action on the ingredient admin table', function () {
    $user = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->assertTableActionExists('view', null, $ingredient)
        ->assertTableActionExists('edit', null, $ingredient);
});

it('renders the catalog create forms in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Ingredient category')
        ->assertSee('Material Identity')
        ->assertSee('Guidance &amp; Media', false)
        ->assertSee('Ingredient guidance')
        ->assertSee('Ingredient image')
        ->assertSee('EU / COSING functions')
        ->assertSee('Composite Components')
        ->assertDontSee('Internal Metadata');

    $this->get(IngredientSapProfileResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Saponification Data')
        ->assertSee('Iodine')
        ->assertSee('INS');
});

it('renders the compliance resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender Essential Oil',
        'source_key' => 'EO1',
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
        ->assertSee('Material Identity')
        ->assertSee('Aromatic Compliance')
        ->assertSee('LINALOOL')
        ->assertSee('Guidance &amp; Media', false)
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
});
