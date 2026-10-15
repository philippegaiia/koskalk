<?php

use App\Enums\IngredientCategory;
use App\Enums\SoapProcess;
use App\Filament\Resources\ProductFamilies\Pages\EditProductFamily;
use App\Filament\Resources\ProductFamilies\Pages\ListProductFamilies;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RecipeWorkbenchService;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('seeds the liquid soap family inactive on its own bench beside the soap and cosmetic families', function () {
    $this->seed(ProductFamilySeeder::class);

    $families = ProductFamily::query()->get()->keyBy('slug');

    expect($families['soap']->soap_process)->toBe(SoapProcess::Bar)
        ->and($families['soap']->usesLiquidSoapPasteBench())->toBeFalse()
        ->and($families['cosmetic']->soap_process)->toBeNull()
        ->and($families['liquid-soap']->soap_process)->toBe(SoapProcess::LiquidPaste)
        ->and($families['liquid-soap']->calculation_basis)->toBe('initial_oils')
        ->and($families['liquid-soap']->is_active)->toBeFalse()
        ->and($families['liquid-soap']->usesLiquidSoapPasteBench())->toBeTrue();
});

it('does not reopen or reshape the liquid soap family when seeding again', function () {
    $this->seed(ProductFamilySeeder::class);
    ProductFamily::query()->where('slug', 'liquid-soap')->update(['is_active' => true, 'name' => 'Liquid Soap Paste']);

    $this->seed(ProductFamilySeeder::class);

    expect(ProductFamily::query()->where('slug', 'liquid-soap')->firstOrFail())
        ->is_active->toBeTrue()
        ->name->toBe('Liquid Soap Paste');
});

it('keeps a new liquid soap formula closed until its family is active', function (bool $active, int $status) {
    $family = ProductFamily::factory()->liquidSoapPaste()->create(['is_active' => $active]);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('recipes.create', ['family' => 'liquid-soap', 'type' => $productType->slug]))
        ->assertStatus($status);
})->with([
    'inactive family' => [false, 404],
    'active family' => [true, 200],
]);

it('keeps a saved liquid soap formula closed until its family is active', function (bool $active, int $status) {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create(['is_active' => $active]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);

    $this->actingAs($workspace->owner)
        ->get(route('recipes.edit', $recipe))
        ->assertStatus($status);
})->with([
    'inactive family' => [false, 404],
    'active family' => [true, 200],
]);

it('refuses to mount the workbench for an inactive liquid soap family', function (string $source) {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create(['is_active' => false]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);

    $parameters = $source === 'saved formula'
        ? ['recipe' => $recipe]
        : ['productFamilySlug' => $family->slug];

    Livewire::test(RecipeWorkbench::class, $parameters)->assertStatus(404);
})->with(['saved formula', 'family slug']);

it('tells the browser an active liquid soap family uses the liquid paste process', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => $family->slug]);

    expect($component->html())->toContain('liquid_paste');
});

it('keeps a KOH formula of the soap family on the soap workbench', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.188]);
    $version = app(RecipeWorkbenchService::class)->save($user, $family, [
        'name' => 'Potassium soap',
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'none',
        'editing_mode' => 'percentage',
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'lye_concentration',
        'water_value' => 30,
        'superfat' => 5,
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'additives' => [],
            'fragrance' => [],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertOk()
        ->assertSeeLivewire(RecipeWorkbench::class);
});

it('shows each family bench to administrators without letting them change it', function () {
    $soap = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    ProductFamily::factory()->liquidSoapPaste()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListProductFamilies::class)
        ->assertSee('Bar soap')
        ->assertSee('Liquid soap paste');

    Livewire::test(EditProductFamily::class, ['record' => $soap->id])
        ->fillForm(['soap_process' => SoapProcess::LiquidPaste->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($soap->refresh()->soap_process)->toBe(SoapProcess::Bar);
});
