<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the real soap workbench to anonymous calculator visitors', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);

    publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil);
    publicCalculatorIngredient('Lavender Essential Oil', IngredientCategory::EssentialOil);
    publicCalculatorIngredient('French Green Clay', IngredientCategory::Clay);

    $this->get(route('calculator'))
        ->assertSuccessful()
        ->assertSeeText('Soap lye calculator')
        ->assertSeeText('Formula')
        ->assertSeeText('Reaction core')
        ->assertSeeText('Additives and aromatics')
        ->assertSeeText('Fatty acid profile')
        ->assertSeeText('Output')
        ->assertSeeText('Save this formula')
        ->assertSeeText('Create free account')
        ->assertSeeText('Use Save this formula so your current draft follows you into registration.')
        ->assertSee('form="public-calculator-save-form"', false)
        ->assertSee('id="public-calculator-save-form"', false)
        ->assertSeeText('Advertisement')
        ->assertSeeText('Number format')
        ->assertSeeText('English (UK) · 1,234.56')
        ->assertSeeText('French · 1 234,56')
        ->assertSee('x-model="numberLocale"', false)
        ->assertSee('name="draft"', false)
        ->assertSee('x-bind:value="serializeDraftJson()"', false)
        ->assertDontSeeText('Create account')
        ->assertDontSeeText('Save with free account')
        ->assertDontSeeText('Add private ingredients')
        ->assertDontSeeText('Unlock packaging and costing')
        ->assertSee('recipeWorkbench(', false)
        ->assertSee('Olive Oil')
        ->assertSee('Lavender Essential Oil')
        ->assertSee('French Green Clay')
        ->assertDontSeeText('Packaging plan')
        ->assertDontSeeText('Costing settings');
});

it('shows signed in calculator users in the aside while keeping ads for free accounts', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);
    $user = User::factory()->create([
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('calculator'))
        ->assertSuccessful()
        ->assertSeeText('Marie Maker')
        ->assertSeeText('marie@example.com')
        ->assertSeeText('Free account')
        ->assertSeeText('Advertisement')
        ->assertSeeText('Sign out')
        ->assertDontSeeText('Number format')
        ->assertDontSee('x-model="numberLocale"', false)
        ->assertDontSee('grid size-10 shrink-0 place-items-center rounded-lg bg-[var(--color-accent-soft)]', false)
        ->assertDontSeeText('Create free account');

    expect(file_get_contents(resource_path('views/layouts/calculator.blade.php')))
        ->not->toContain('auth()->user()?->email');
});

it('marks admin users in the calculator aside', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);
    $admin = User::factory()->admin()->create([
        'name' => 'Admin Maker',
        'email' => 'admin@example.com',
    ]);

    $this->actingAs($admin)
        ->get(route('calculator'))
        ->assertSuccessful()
        ->assertSeeText('Admin Maker')
        ->assertSeeText('Admin')
        ->assertSeeText('Advertisement');
});

it('keeps the public calculator introduction and formula header unframed', function () {
    $calculator = file_get_contents(resource_path('views/calculator/show.blade.php'));
    $publicHeader = view('livewire.dashboard.partials.recipe-workbench.header', [
        'isPublicCalculator' => true,
    ])->render();

    expect($calculator)
        ->toContain('lg:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('calculator.partials.aside')
        ->not->toContain('bg-[color:color-mix(in_oklab,var(--color-panel)_78%,var(--color-surface)_22%)]')
        ->not->toContain('shadow-sm lg:p-5')
        ->and($publicHeader)
        ->not->toContain('sk-card p-5');
});

it('stores the current public calculator formula before registration', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);
    $oil = publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil, kohSap: 0.188);
    $draft = publicCalculatorDraftPayload($oil);

    $this->post(route('calculator.draft.store'), [
        'product_family_slug' => 'soap',
        'draft' => json_encode($draft, JSON_THROW_ON_ERROR),
    ])
        ->assertRedirect(route('register'))
        ->assertSessionHas('public_calculator.pending_formula', fn (array $pendingFormula): bool => $pendingFormula['product_family_slug'] === 'soap'
            && $pendingFormula['draft']['name'] === 'Guest Formula');
});

it('saves a pending public calculator formula after registration', function () {
    $this->seed(PlanSeeder::class);

    ProductFamily::factory()->create(['slug' => 'soap']);
    $oil = publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil, kohSap: 0.188);

    $response = $this
        ->withSession([
            'public_calculator.pending_formula' => [
                'product_family_slug' => 'soap',
                'draft' => publicCalculatorDraftPayload($oil),
            ],
        ])
        ->post(route('register'), [
            'name' => 'Marie Maker',
            'email' => 'marie@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

    $recipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Guest Formula')
        ->firstOrFail();

    $response->assertRedirect(route('recipes.edit', $recipe));

    expect($recipe->ownerUser()?->email)->toBe('marie@example.com');
});

it('keeps anonymous calculator formulas from being saved until registration', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);
    $oil = publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil, kohSap: 0.188);

    Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->call('publish', publicCalculatorDraftPayload($oil))
        ->assertReturned(fn (array $return): bool => ($return['ok'] ?? null) === false
            && str_contains($return['message'] ?? '', 'signed in'));
});

function publicCalculatorIngredient(string $name, IngredientCategory $category, ?float $kohSap = null): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'display_name' => $name,
        'category' => $category,
        'is_potentially_saponifiable' => $category === IngredientCategory::CarrierOil,
        'is_active' => true,
    ]);

    if ($kohSap !== null) {
        IngredientSapProfile::factory()->create([
            'ingredient_id' => $ingredient->id,
            'koh_sap_value' => $kohSap,
        ]);
    }

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function publicCalculatorDraftPayload(Ingredient $ingredient): array
{
    return [
        'name' => 'Guest Formula',
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
