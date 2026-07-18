<?php

use App\Filament\Resources\Allergens\AllergenResource;
use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\Ingredients\Schemas\IngredientForm;
use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use App\Filament\Resources\IngredientSubstanceEntries\IngredientSubstanceEntryResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\RegulatoryRegimeAllergens\RegulatoryRegimeAllergenResource;
use App\Filament\Resources\RegulatoryRegimes\RegulatoryRegimeResource;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\RegulatoryRegimeSubstanceRuleResource;
use App\Filament\Resources\Substances\SubstanceResource;
use App\Filament\Resources\SupportedLocales\Pages\CreateSupportedLocale;
use App\Filament\Resources\SupportedLocales\Pages\EditSupportedLocale;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
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
use App\OwnerType;
use App\Visibility;
use Database\Seeders\PlanSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
        ->assertSeeText('Translate the public ingredient name and guidance.')
        ->assertSeeText('French')
        ->assertSeeText('German')
        ->assertSeeText('Olive Oil')
        ->assertSeeText('English guidance');
});

it('lets admins save platform ingredient translations in Filament', function () {
    $admin = User::factory()->admin()->create();
    SupportedLocale::factory()->create(['code' => 'fr', 'name' => 'French']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->fillForm([
            'translations' => [[
                'locale' => 'fr',
                'display_name' => 'Huile d’olive',
                'info_markdown' => 'Conseils en français',
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(IngredientTranslation::query()
        ->whereBelongsTo($ingredient)
        ->where('locale', 'fr')
        ->firstOrFail()
        ->only(['display_name', 'info_markdown']))
        ->toBe([
            'display_name' => 'Huile d’olive',
            'info_markdown' => 'Conseils en français',
        ]);
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

it('does not offer the platform deletion action for private user ingredients', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionHidden('delete');
});

it('keeps the platform translation editor away from private ingredients', function () {
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
        ->assertSuccessful()
        ->assertDontSeeText('Translate the public ingredient name and guidance.');
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
        ->assertActionExists(TestAction::make('view')->table($ingredient))
        ->assertActionExists(TestAction::make('edit')->table($ingredient));
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

it('renders the plan limits resource in the admin panel', function () {
    $user = User::factory()->admin()->create();
    $this->seed(PlanSeeder::class);
    $plan = Plan::query()->where('slug', 'free-beta')->firstOrFail();

    $this->actingAs($user);

    $this->get(PlanResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Free beta')
        ->assertSee('15')
        ->assertSee('20');

    $this->get(PlanResource::getUrl('edit', ['record' => $plan], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Plan')
        ->assertSee('Limits')
        ->assertSee('Saved recipes')
        ->assertSee('Private ingredients');
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
