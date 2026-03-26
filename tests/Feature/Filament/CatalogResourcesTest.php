<?php

use App\Filament\Resources\Allergens\AllergenResource;
use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use App\Filament\Resources\IngredientVersions\IngredientVersionResource;
use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the catalog list resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
        'source_key' => 'OB1',
    ]);

    $ingredientVersion = IngredientVersion::factory()
        ->for($ingredient)
        ->create([
            'display_name' => 'Olive Oil',
            'source_key' => 'OB1',
        ]);

    IngredientSapProfile::factory()
        ->for($ingredientVersion, 'ingredientVersion')
        ->create([
            'koh_sap_value' => 0.188,
            'oleic' => 70,
        ]);

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil')
        ->assertSee('Carrier Oil');

    $this->get(IngredientVersionResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil');

    $this->get(IngredientSapProfileResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Olive Oil');
});

it('renders the catalog create forms in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user);

    $this->get(IngredientResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Ingredient category')
        ->assertSee('Source Traceability');

    $this->get(IngredientVersionResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Version Identity')
        ->assertSee('Display And Regulatory Names');

    $this->get(IngredientSapProfileResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Saponification Data')
        ->assertSee('Legacy Fatty Acid Fallback');
});

it('renders the compliance resources in the admin panel', function () {
    $user = User::factory()->admin()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'source_key' => 'EO1',
    ]);

    $ingredientVersion = IngredientVersion::factory()
        ->for($ingredient)
        ->create([
            'display_name' => 'Lavender Essential Oil',
            'source_key' => 'EO1',
        ]);

    $allergen = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    IngredientAllergenEntry::factory()
        ->for($ingredientVersion, 'ingredientVersion')
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
        ->for($ingredientVersion, 'ingredientVersion')
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

    $this->get(IngredientVersionResource::getUrl('edit', ['record' => $ingredientVersion], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Aromatic Compliance')
        ->assertSee('LINALOOL');
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
        ->assertSee('Product Family Mapping');

    $this->get(IfraCertificateResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Certificate Identity')
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
