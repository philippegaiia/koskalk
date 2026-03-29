<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the public ingredients index with only the current users private ingredients', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownedIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OWNED',
    ]);

    IngredientVersion::factory()->for($ownedIngredient)->create([
        'display_name' => 'My Glycerin',
        'inci_name' => 'GLYCERIN',
    ]);

    $hiddenIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-HIDDEN',
    ]);

    IngredientVersion::factory()->for($hiddenIngredient)->create([
        'display_name' => 'Hidden Glycerin',
        'inci_name' => 'GLYCERIN',
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Private catalog records')
        ->assertSee('My Glycerin')
        ->assertDontSee('Hidden Glycerin');
});

it('does not allow editing another users private ingredient', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OTHER',
    ]);

    IngredientVersion::factory()->for($ingredient)->create([
        'display_name' => 'Other User Ingredient',
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient->id))
        ->assertNotFound();
});

it('renders the public ingredient create page for signed in users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSee('Create ingredient')
        ->assertSee('Supplier reference');
});
