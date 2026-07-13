<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\IngredientFormulaUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('shows platform ingredients whether or not the user has priced them', function () {
    $user = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $coconut = Ingredient::factory()->create([
        'display_name' => 'Coconut Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $olive->id,
        'price_per_kg' => 5.2500,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Use platform ingredients or maintain your own.')
        ->assertSee('Ingredient catalog')
        ->assertSee('All ingredients')
        ->assertSeeHtml('aria-label="Ingredient catalog filters"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary justify-center"')
        ->assertDontSeeHtml('fi-ta')
        ->assertSeeHtml('role="radiogroup"')
        ->assertSeeHtml('aria-checked="true"')
        ->assertSee('Price/kg (EUR)')
        ->assertSee('5.25')
        ->assertDontSee('5.2500')
        ->assertSee('Olive Oil')
        ->assertSee('Coconut Oil')
        ->assertSee('Platform');
});

it('shows the ingredient price column in the users current default currency', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Price/kg (GBP)');
});

it('renders inline ingredient prices with the users saved English number format', function () {
    $user = User::factory()->create(['number_locale' => 'en_GB']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Priced oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => 0.1000,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSeeHtml('type="text"')
        ->assertSeeHtml('inputmode="decimal"')
        ->assertSeeHtml('value="0.10"');
});

it('does not show inactive platform ingredients in the unified table', function () {
    $user = User::factory()->create();

    $active = Ingredient::factory()->create([
        'display_name' => 'Active Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $inactive = Ingredient::factory()->create([
        'display_name' => 'Inactive Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => false,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee($active->display_name)
        ->assertDontSee($inactive->display_name);
});

it('can filter the unified ingredient table by platform catalog records', function () {
    $user = User::factory()->create();

    $platform = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $mine = Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('setOwnershipFilter', 'platform')
        ->assertSee($platform->display_name)
        ->assertDontSee($mine->display_name);
});

it('shows user-owned ingredients in the unified table', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Other User Oil',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('My Lavender')
        ->assertSeeHtml('aria-label="User-created or user-modified ingredient"')
        ->assertSee('Data has not been verified by Soapkraft.')
        ->assertDontSee('Other User Oil');
});

it('shows private ingredient usage in the mine filter and plan allowance', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()->create([
        'display_name' => 'My Limited Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Mine (1)')
        ->assertSee('1 of 20 private ingredients');
});

it('singularizes the finite private ingredient allowance from the plan limit', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 1)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('1 of 1 private ingredient')
        ->assertDontSee('1 of 1 private ingredients');
});

it('looks up formula usage for only private ingredients on the current page', function () {
    $user = User::factory()->create();
    $ownedIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    mock(IngredientFormulaUsageService::class, function ($mock) use ($user, $ownedIngredient): void {
        $mock->shouldReceive('forIngredients')
            ->once()
            ->withArgs(fn (User $resolvedUser, Collection $ingredients): bool => $resolvedUser->is($user)
                && $ingredients->modelKeys() === [$ownedIngredient->id])
            ->andReturn([]);
    });

    actingAs($user);

    Livewire::test(IngredientsIndex::class);
});

it('updates a user ingredient price via the price endpoint', function () {
    $user = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $olive->id,
        'price_per_kg' => 5.2500,
        'currency' => 'EUR',
        'last_used_at' => now()->subDay(),
    ]);

    actingAs($user);

    $response = $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
    ]);

    $response->assertSuccessful();

    $price = UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $olive->id)
        ->first();

    expect((float) $price->price_per_kg)->toBe(6.5);
    expect($price->last_used_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('does not allow pricing another users private ingredient through the endpoint', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Other User Lavender',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => '6.5000',
    ])->assertNotFound();

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $ingredient->id)
        ->exists())->toBeFalse();
});

it('uses the users default currency when creating a price via the price endpoint', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'USD',
    ]);

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
    ])->assertSuccessful();

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $olive->id)
        ->value('currency'))->toBe('USD');
});

it('preserves an existing price currency when updating via the price endpoint', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'USD',
    ]);

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $olive->id,
        'price_per_kg' => 5.2500,
        'currency' => 'CHF',
        'last_used_at' => now()->subDay(),
    ]);

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
    ])->assertSuccessful();

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $olive->id)
        ->value('currency'))->toBe('CHF');
});

it('uses the users default currency when creating a price from the ingredient table', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('updateIngredientPrice', $ingredient->id, '7.2500');

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('currency'))->toBe('GBP');
});
