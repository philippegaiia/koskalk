<?php

use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPublicIdRecords(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'visibility' => Visibility::Private,
        'name' => 'Private formula',
    ]);
    $version = RecipeVersion::withoutGlobalScopes()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'version_number' => 1,
        'is_current' => true,
        'name' => 'Private formula',
    ]);
    $batch = ProductionBatch::factory()->for($owner)->for($recipe)->for($version, 'recipeVersion')->create();
    $ingredient = Ingredient::factory()->create();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1,
        'currency' => 'EUR',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version', 'batch', 'ingredient', 'packagingItem');
}

it('assigns UUIDv4 public identifiers while retaining numeric internal keys', function () {
    $records = createPublicIdRecords();

    foreach ([$records['workspace'], $records['recipe'], $records['version'], $records['batch'], $records['ingredient'], $records['packagingItem']] as $record) {
        expect($record->getKey())->toBeInt()
            ->and($record->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($record->getRouteKeyName())->toBe('public_id');
    }
});

it('routes private records by public UUID and rejects numeric enumeration', function () {
    $records = createPublicIdRecords();
    $owner = $records['owner'];
    $recipe = $records['recipe'];
    $version = $records['version'];
    $batch = $records['batch'];
    $ingredient = $records['ingredient'];
    $packagingItem = $records['packagingItem'];

    $this->actingAs($owner);

    expect(route('recipes.saved', $recipe))->toContain($recipe->public_id)
        ->not->toContain('/'.$recipe->id.'/')
        ->and(route('recipes.version', ['recipe' => $recipe, 'version' => $version]))->toContain($version->public_id)
        ->and(route('production-batches.show', $batch))->toContain($batch->public_id)
        ->and(route('ingredients.edit', $ingredient))->toContain($ingredient->public_id)
        ->and(route('packaging-items.edit', $packagingItem))->toContain($packagingItem->public_id);

    $this->get(route('recipes.saved', $recipe))->assertOk();
    $this->get('/dashboard/recipes/'.$recipe->id.'/saved')->assertNotFound();
    $this->get(route('production-batches.show', $batch))->assertOk();
    $this->get('/dashboard/production-batches/'.$batch->id)->assertNotFound();
    $this->get(route('ingredients.edit', $ingredient))->assertOk();
    $this->get('/dashboard/ingredients/'.$ingredient->id)->assertNotFound();
    $this->get(route('packaging-items.edit', $packagingItem))->assertOk();
    $this->get('/dashboard/packaging-items/'.$packagingItem->id)->assertNotFound();
});
