<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows a duplicate action in the ingredients page header', function () {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Duplicate a Soapkraft ingredient');
});

it('searches platform ingredients for duplication', function () {
    $user = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'Lavender 40/42',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Peppermint Oil',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->getJson(route('ingredients.search-platform').'?q=lavender');

    $response->assertSuccessful();
    $results = $response->json();
    expect($results)->toHaveCount(1);
    expect($results[0]['name'])->toBe('Lavender 40/42');
});

it('creates a workspace-owned copy when duplicating a platform ingredient', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'display_name' => 'Rosemary Oil',
        'inci_name' => 'ROSMARINUS OFFICINALIS OIL',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('ok'))->toBeTrue();

    $workspace = $user->fresh()->company();

    $copy = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace->id)
        ->first();

    expect($copy)->not->toBeNull();
    expect($copy->display_name)->toBe('Rosemary Oil');
    expect($copy->owner_type)->toBe(OwnerType::Workspace);
    expect($copy->owner_id)->toBe($workspace->id);
    expect($copy->workspace_id)->toBe($workspace->id);
    expect($copy->featured_image_path)->toBeNull();
});
