<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Models\Workspace;
use App\OwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

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
        ->assertSee('Olive Oil')
        ->assertSee('Coconut Oil')
        ->assertSee('Platform');
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
        ->loadTable()
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
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
        ->loadTable()
        ->filterTable('ownership', 'platform')
        ->assertCanSeeTableRecords([$platform])
        ->assertCanNotSeeTableRecords([$mine]);
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
        ->assertDontSee('Other User Oil');
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
        ->loadTable()
        ->call('updateTableColumnState', 'user_price_per_kg', (string) $ingredient->getKey(), '7.2500');

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('currency'))->toBe('GBP');
});
