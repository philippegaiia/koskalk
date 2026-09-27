<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IngredientFormulaMutationService;
use App\Services\PackagingItemFormulaMutationService;
use App\Services\RecipeEditingService;
use App\Services\RecipeMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('atomically protects permanently locked and actively reserved formulas from material deletion', function (string $operation, string $protection): void {
    $fixture = protectedMaterialFixture($operation);
    if ($protection === 'locked') {
        $fixture['recipe']->forceFill(['locked_at' => now(), 'locked_by' => $fixture['user']->id])->save();
    } else {
        app(RecipeEditingService::class)->acquire($fixture['recipe'], $fixture['user'], 'a55a9f20-fae8-4d19-8d30-4b683032d80a');
    }
    $recipeRevision = (int) $fixture['recipe']->fresh()->edit_revision;
    $costingRevision = (int) $fixture['costing']->fresh()->edit_revision;

    expect(fn () => mutateProtectedMaterial($fixture, $operation))->toThrow(function (ValidationException $exception) use ($operation, $protection): void {
        expect($exception->errors())->toBe([
            $operation === 'packaging-remove' ? 'packaging_item' : 'ingredient' => [
                __($protection === 'locked' ? 'editing.formula_locked' : 'editing.reserved'),
            ],
        ]);
    });

    expect($fixture['material']->fresh())->not->toBeNull()
        ->and($fixture['item']->fresh())->not->toBeNull()
        ->and($fixture['costingItem']->fresh())->not->toBeNull()
        ->and((int) $fixture['recipe']->fresh()->edit_revision)->toBe($recipeRevision)
        ->and((int) $fixture['costing']->fresh()->edit_revision)->toBe($costingRevision);
})->with(['ingredient-remove', 'ingredient-replace', 'packaging-remove'])->with(['locked', 'reserved']);

it('allows material changes after reservation expiry and invalidates stale formula and costing drafts', function (string $operation): void {
    $fixture = protectedMaterialFixture($operation);
    $editing = app(RecipeEditingService::class);
    $token = 'b55a9f20-fae8-4d19-8d30-4b683032d80a';
    $editing->acquire($fixture['recipe'], $fixture['user'], $token);
    $recipeRevision = (int) $fixture['recipe']->fresh()->edit_revision;
    $costingRevision = (int) $fixture['costing']->fresh()->edit_revision;
    $this->travel(RecipeEditingService::LeaseSeconds + 1)->seconds();

    mutateProtectedMaterial($fixture, $operation);

    expect($fixture['material']->fresh())->toBeNull()
        ->and((int) $fixture['recipe']->fresh()->edit_revision)->toBe($recipeRevision + 1)
        ->and((int) $fixture['costing']->fresh()->edit_revision)->toBe($costingRevision + 1);
    $editing->acquire($fixture['recipe']->fresh(), $fixture['user'], $token);
    expect(fn () => app(RecipeMutationGuard::class)->run(
        $fixture['recipe'], $fixture['user'], $token, $recipeRevision, $fixture['version']->id,
        fn () => throw new RuntimeException('A stale callback must not run'), $costingRevision,
    ))->toThrow(ValidationException::class);
})->with(['ingredient-remove', 'ingredient-replace', 'packaging-remove']);

it('leaves every formula and costing unchanged when one material consumer is locked', function (string $operation): void {
    $fixture = protectedMaterialFixture($operation);
    $ownership = ['owner_type' => OwnerType::User, 'owner_id' => $fixture['user']->id, 'workspace_id' => $fixture['workspace']->id, 'visibility' => Visibility::Private];
    $lockedRecipe = Recipe::factory()->create($ownership + ['locked_at' => now(), 'locked_by' => $fixture['user']->id]);
    $lockedVersion = RecipeVersion::factory()->create($ownership + ['recipe_id' => $lockedRecipe->id]);
    $lockedCosting = RecipeVersionCosting::query()->create(['recipe_version_id' => $lockedVersion->id, 'user_id' => $fixture['user']->id, 'currency' => 'EUR']);
    if ($operation === 'packaging-remove') {
        $lockedItem = RecipeVersionPackagingItem::query()->create(['recipe_version_id' => $lockedVersion->id, 'packaging_item_id' => $fixture['material']->id, 'name' => 'Protected package', 'components_per_unit' => 1, 'position' => 1]);
        $lockedCostingItem = RecipeVersionCostingPackagingItem::query()->create(['recipe_version_costing_id' => $lockedCosting->id, 'packaging_item_id' => $fixture['material']->id, 'name' => 'Protected package', 'unit_cost' => '2', 'quantity' => 1]);
    } else {
        $lockedItem = RecipeItem::factory()->create($ownership + ['recipe_version_id' => $lockedVersion->id, 'ingredient_id' => $fixture['material']->id]);
        $lockedCostingItem = RecipeVersionCostingItem::query()->create(['recipe_version_costing_id' => $lockedCosting->id, 'ingredient_id' => $fixture['material']->id, 'phase_key' => 'main', 'position' => 1]);
    }
    $records = collect([$fixture['material'], $fixture['recipe'], $fixture['version'], $fixture['costing'], $fixture['item'], $fixture['costingItem'], $lockedRecipe, $lockedVersion, $lockedCosting, $lockedItem, $lockedCostingItem]);
    $before = $records->map(fn ($record): array => $record->fresh()->getAttributes());

    expect(fn () => mutateProtectedMaterial($fixture, $operation))->toThrow(ValidationException::class);

    $records->each(fn ($record, int $index) => expect($record->fresh()?->getAttributes())->toBe($before[$index]));
})->with(['ingredient-remove', 'ingredient-replace', 'packaging-remove']);

it('rolls back composite replacement when an ancestor is used by a locked formula', function (): void {
    $fixture = protectedMaterialFixture('ingredient-replace');
    $ancestor = Ingredient::factory()->create([
        'owner_type' => OwnerType::User, 'owner_id' => $fixture['user']->id,
        'workspace_id' => $fixture['workspace']->id, 'visibility' => Visibility::Private,
        'category' => IngredientCategory::Other,
    ]);
    $component = IngredientComponent::factory()->create([
        'ingredient_id' => $ancestor->id, 'component_ingredient_id' => $fixture['material']->id,
    ]);
    $fixture['item']->update(['ingredient_id' => $ancestor->id]);
    $fixture['costingItem']->update(['ingredient_id' => $ancestor->id]);
    $fixture['recipe']->forceFill(['locked_at' => now(), 'locked_by' => $fixture['user']->id])->save();
    $records = collect([$component, $ancestor, $fixture['material'], $fixture['recipe'], $fixture['version'], $fixture['costing'], $fixture['item'], $fixture['costingItem']]);
    $before = $records->map(fn ($record): array => $record->fresh()->getAttributes());

    expect(fn () => mutateProtectedMaterial($fixture, 'ingredient-replace'))->toThrow(ValidationException::class);

    $records->each(fn ($record, int $index) => expect($record->fresh()?->getAttributes())->toBe($before[$index]));
});

/** @return array<string, mixed> */
function protectedMaterialFixture(string $operation): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $ownership = ['owner_type' => OwnerType::User, 'owner_id' => $user->id, 'workspace_id' => $workspace->id, 'visibility' => Visibility::Private];
    $recipe = Recipe::factory()->create($ownership + ['created_by' => $user->id]);
    $version = RecipeVersion::factory()->create($ownership + ['recipe_id' => $recipe->id]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $user->id, 'currency' => 'EUR']);

    if ($operation === 'packaging-remove') {
        $material = PackagingItem::factory()->create(['workspace_id' => $workspace->id, 'created_by_user_id' => $user->id]);
        $item = RecipeVersionPackagingItem::query()->create(['recipe_version_id' => $version->id, 'packaging_item_id' => $material->id, 'name' => $material->name, 'components_per_unit' => 1, 'position' => 1]);
        $costingItem = RecipeVersionCostingPackagingItem::query()->create(['recipe_version_costing_id' => $costing->id, 'packaging_item_id' => $material->id, 'name' => $material->name, 'unit_cost' => '1', 'quantity' => 1]);
    } else {
        $material = Ingredient::factory()->create($ownership + ['category' => IngredientCategory::Other]);
        $item = RecipeItem::factory()->create($ownership + ['recipe_version_id' => $version->id, 'ingredient_id' => $material->id]);
        $costingItem = RecipeVersionCostingItem::query()->create(['recipe_version_costing_id' => $costing->id, 'ingredient_id' => $material->id, 'phase_key' => 'main', 'position' => 1]);
    }

    return compact('user', 'workspace', 'recipe', 'version', 'costing', 'material', 'item', 'costingItem');
}

/** @param array<string, mixed> $fixture */
function mutateProtectedMaterial(array $fixture, string $operation): void
{
    if ($operation === 'packaging-remove') {
        app(PackagingItemFormulaMutationService::class)->removeEverywhereAndDelete($fixture['user'], $fixture['material']);
    } elseif ($operation === 'ingredient-replace') {
        $replacement = Ingredient::factory()->create([
            'owner_type' => OwnerType::User, 'owner_id' => $fixture['user']->id,
            'workspace_id' => $fixture['workspace']->id, 'visibility' => Visibility::Private,
            'category' => IngredientCategory::Other,
        ]);
        app(IngredientFormulaMutationService::class)->replaceEverywhereAndDelete($fixture['user'], $fixture['material'], $replacement);
    } else {
        app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($fixture['user'], $fixture['material']);
    }
}
