<?php

use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LiveCostingPricePropagationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('invalidates each affected costing once without changing authorship or another company', function (string $material): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $otherWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $costings = collect([$workspace, $workspace, $otherWorkspace])->map(function (Workspace $company) use ($owner, $ingredient, $packaging, $material): RecipeVersionCosting {
        $ownership = ['owner_type' => OwnerType::Workspace, 'owner_id' => $company->id, 'workspace_id' => $company->id];
        $recipe = Recipe::factory()->create($ownership);
        $version = RecipeVersion::factory()->for($recipe)->create($ownership);
        $costing = RecipeVersionCosting::query()->create([
            'recipe_version_id' => $version->id,
            'user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'currency' => 'EUR',
        ]);
        foreach ([0, 1] as $position) {
            if ($material === 'ingredient') {
                $costing->items()->create(['ingredient_id' => $ingredient->id, 'phase_key' => 'oils', 'position' => $position, 'price_per_kg' => '2']);
            } else {
                $costing->packagingItems()->create(['packaging_item_id' => $packaging->id, 'name' => 'Box', 'unit_cost' => '2', 'quantity' => '1']);
            }
        }

        return $costing;
    });

    $service = app(LiveCostingPricePropagationService::class);
    if ($material === 'ingredient') {
        $service->ingredientPriceChanged($workspace, $ingredient->id, '3', $costings[1]->id);
        $service->ingredientPriceChanged($workspace, $ingredient->id, '3', $costings[1]->id);
    } else {
        $service->packagingPriceChanged($workspace, $packaging->id, '3', $costings[1]->id);
        $service->packagingPriceChanged($workspace, $packaging->id, '3', $costings[1]->id);
    }

    foreach ($costings as $index => $costing) {
        $costing->refresh();
        $prices = $material === 'ingredient'
            ? $costing->items()->pluck('price_per_kg')
            : $costing->packagingItems()->pluck('unit_cost');
        expect((int) $costing->edit_revision)->toBe($index === 0 ? 1 : 0)
            ->and($costing->user_id)->toBe($owner->id)
            ->and($costing->updated_by_user_id)->toBe($owner->id)
            ->and($prices->all())->toBe(array_fill(0, 2, $index === 0 ? '3.0000' : '2.0000'));
    }
})->with(['ingredient', 'packaging']);
