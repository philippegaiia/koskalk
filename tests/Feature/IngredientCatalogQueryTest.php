<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IngredientCatalogQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('preserves ingredient ownership, pricing, search, and stable sort filters', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $foreignWorkspace = Workspace::factory()->create();

    $firstAloe = Ingredient::factory()->create([
        'display_name' => 'Aloe Vera',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $secondAloe = Ingredient::factory()->create([
        'display_name' => 'Aloe Vera',
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $lavender = Ingredient::factory()->create([
        'display_name' => 'Lavender Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $personal = Ingredient::factory()->create([
        'display_name' => 'Personal Material',
        'owner_type' => OwnerType::User,
        'owner_id' => $actor->id,
        'is_active' => true,
    ]);
    $workspaceOwned = Ingredient::factory()->create([
        'display_name' => 'Workspace Material',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'display_name' => 'Foreign Private Material',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $foreignWorkspace->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'display_name' => 'Inactive Public Material',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => false,
    ]);

    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $firstAloe->id,
        'price_per_canonical_unit' => '0.001000000000',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $foreignWorkspace->id,
        'ingredient_id' => $lavender->id,
        'price_per_canonical_unit' => '0.002000000000',
    ]);

    $catalog = app(IngredientCatalogQuery::class);
    $baseFilters = ['sortField' => 'display_name', 'sortDirection' => 'asc'];

    expect($catalog->query($actor, $workspace, $baseFilters)->pluck('id')->all())
        ->toBe([$firstAloe->id, $secondAloe->id, $lavender->id, $personal->id, $workspaceOwned->id])
        ->and($catalog->query($actor, $workspace, $baseFilters + ['ownershipFilter' => 'mine'])->pluck('id')->all())
        ->toBe([$personal->id, $workspaceOwned->id])
        ->and($catalog->query($actor, $workspace, $baseFilters + ['ownershipFilter' => 'platform'])->pluck('id')->all())
        ->toBe([$firstAloe->id, $secondAloe->id, $lavender->id])
        ->and($catalog->query($actor, $workspace, $baseFilters + ['ownershipFilter' => 'priced'])->pluck('id')->all())
        ->toBe([$firstAloe->id])
        ->and($catalog->query($actor, $workspace, $baseFilters + [
            'ownershipFilter' => 'platform',
            'search' => 'lavender',
        ])->pluck('id')->all())
        ->toBe([$lavender->id])
        ->and($catalog->query($actor, $workspace, [
            'sortField' => 'display_name',
            'sortDirection' => 'desc',
            'ownershipFilter' => 'platform',
            'search' => 'aloe',
        ])->pluck('id')->all())
        ->toBe([$secondAloe->id, $firstAloe->id]);
});
