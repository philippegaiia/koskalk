<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\OwnerType;
use App\Services\EntitlementService;
use App\Services\RecipeWorkbenchService;
use App\Services\UserIngredientAuthoringService;
use App\Visibility;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('blocks new recipes when the active plan recipe limit is reached', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $plan = Plan::factory()
        ->hasLimit('saved_recipes', 15)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Recipe::factory()
        ->count(15)
        ->create([
            'product_family_id' => $soapFamily->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $usage = app(EntitlementService::class)->usageFor($user);

    expect($usage['saved_recipes'])
        ->toMatchArray([
            'used' => 15,
            'limit' => 15,
            'remaining' => 0,
            'allowed' => false,
        ]);
});

it('uses the editable plan limit for private ingredient creation', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()
        ->count(19)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $usage = app(EntitlementService::class)->usageFor($user);

    expect($usage['private_ingredients'])
        ->toMatchArray([
            'used' => 19,
            'limit' => 20,
            'remaining' => 1,
            'allowed' => true,
        ]);
});

it('returns the same private ingredient usage from the focused method', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()
        ->count(2)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $entitlements = app(EntitlementService::class);

    expect($entitlements->privateIngredientUsageFor($user))
        ->toBe($entitlements->usageFor($user)['private_ingredients']);
});

it('rejects saving a new recipe when the recipe plan limit is reached', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = entitlementCarrierOilIngredient();
    $plan = Plan::factory()
        ->hasLimit('saved_recipes', 15)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Recipe::factory()
        ->count(15)
        ->create([
            'product_family_id' => $soapFamily->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    expect(fn () => app(RecipeWorkbenchService::class)->save(
        $user,
        $soapFamily,
        entitlementSoapDraftPayload($ingredient),
    ))->toThrow(ValidationException::class, '15 saved recipes');
});

it('rejects saving a new recipe through the workbench when the recipe plan limit is reached', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = entitlementCarrierOilIngredient();
    $plan = Plan::factory()
        ->hasLimit('saved_recipes', 15)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Recipe::factory()
        ->count(15)
        ->create([
            'product_family_id' => $soapFamily->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->call('save', entitlementSoapDraftPayload($ingredient))
        ->assertReturned(fn (array $return): bool => ($return['ok'] ?? null) === false
            && str_contains($return['message'] ?? '', '15 saved recipes'));
});

it('rejects creating a private ingredient when the ingredient plan limit is reached', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()
        ->count(20)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->create([
        'name' => 'Calendula Flowers',
        'category' => IngredientCategory::Additive->value,
        'inci_name' => 'CALENDULA OFFICINALIS FLOWER',
    ], $user))->toThrow(ValidationException::class, '20 private ingredients');
});

it('rejects recording a production batch when the production batch plan limit is reached', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('production_batches', 1)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    ProductionBatch::factory()->create([
        'user_id' => $user->id,
    ]);

    $usage = app(EntitlementService::class)->usageFor($user);

    expect($usage['production_batches'])
        ->toMatchArray([
            'used' => 1,
            'limit' => 1,
            'remaining' => 0,
            'allowed' => false,
        ]);

    expect(fn () => app(EntitlementService::class)->assertCanCreateProductionBatch($user))
        ->toThrow(ValidationException::class, '1 saved production batches');
});

it('seeds the initial free registered plan limits', function () {
    $this->seed(PlanSeeder::class);

    $plan = Plan::query()
        ->where('slug', 'free-beta')
        ->with('limits')
        ->firstOrFail();

    expect($plan->is_default)->toBeTrue()
        ->and($plan->limits->pluck('value', 'key')->all())
        ->toMatchArray([
            'saved_recipes' => 15,
            'private_ingredients' => 20,
            'production_batches' => 0,
        ]);
});

it('keeps only one default plan', function () {
    $firstPlan = Plan::factory()->create([
        'slug' => 'first-plan',
        'is_default' => true,
    ]);
    $secondPlan = Plan::factory()->create([
        'slug' => 'second-plan',
        'is_default' => true,
    ]);

    expect($firstPlan->refresh()->is_default)->toBeFalse()
        ->and($secondPlan->refresh()->is_default)->toBeTrue();

    $firstPlan->update(['is_default' => true]);

    expect($firstPlan->refresh()->is_default)->toBeTrue()
        ->and($secondPlan->refresh()->is_default)->toBeFalse();
});

function entitlementCarrierOilIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function entitlementSoapDraftPayload(Ingredient $ingredient): array
{
    return [
        'name' => 'Limit Test Soap',
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
