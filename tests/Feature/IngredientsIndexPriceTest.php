<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\OwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows priced platform ingredients in the unified table', function () {
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
        ->assertDontSee('Coconut Oil');
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
