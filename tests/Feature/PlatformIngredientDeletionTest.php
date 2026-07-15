<?php

use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\IngredientTranslation;
use App\Models\ProductionBatchIngredient;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Services\PlatformIngredientDeletionService;
use App\Visibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    Storage::fake('public');
});

it('deletes an unused platform ingredient with its catalog children and public media', function () {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'featured_image_path' => 'ingredients/featured-images/accidental.webp',
        'icon_image_path' => 'ingredients/icons/accidental.webp',
    ]);
    SupportedLocale::factory()->create(['code' => 'fr']);
    $translation = IngredientTranslation::factory()->for($ingredient)->create();
    Storage::disk(MediaStorage::publicDisk())->put($ingredient->featured_image_path, 'featured');
    Storage::disk(MediaStorage::publicDisk())->put($ingredient->icon_image_path, 'icon');

    app(PlatformIngredientDeletionService::class)->delete($admin, $ingredient);

    $this->assertModelMissing($ingredient);
    $this->assertModelMissing($translation);
    Storage::disk(MediaStorage::publicDisk())->assertMissing($ingredient->featured_image_path);
    Storage::disk(MediaStorage::publicDisk())->assertMissing($ingredient->icon_image_path);
});

it('blocks platform ingredient deletion while external records still depend on it', function (string $usage) {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $dependent = match ($usage) {
        'formula' => RecipeItem::factory()->create(['ingredient_id' => $ingredient->id]),
        'costing' => RecipeVersionCostingItem::query()->create([
            'recipe_version_costing_id' => RecipeVersionCosting::query()->create([
                'recipe_version_id' => RecipeVersion::factory()->create()->id,
                'user_id' => $customer->id,
                'currency' => 'EUR',
            ])->id,
            'ingredient_id' => $ingredient->id,
            'phase_key' => 'main',
            'position' => 1,
        ]),
        'composite' => IngredientComponent::factory()->create([
            'ingredient_id' => Ingredient::factory()->create()->id,
            'component_ingredient_id' => $ingredient->id,
        ]),
        'price memory' => UserIngredientPrice::query()->create([
            'user_id' => $customer->id,
            'ingredient_id' => $ingredient->id,
            'price_per_kg' => 5.5,
            'currency' => 'EUR',
        ]),
        'production batch' => ProductionBatchIngredient::factory()->create([
            'ingredient_id' => $ingredient->id,
        ]),
    };

    $exception = null;

    try {
        app(PlatformIngredientDeletionService::class)->delete($admin, $ingredient);
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('ingredient')
        ->and($exception?->errors()['ingredient'][0])->toContain('Deactivate it instead');

    $this->assertModelExists($ingredient);
    $this->assertModelExists($dependent);
})->with([
    'formula item' => ['formula'],
    'costing item' => ['costing'],
    'composite ingredient' => ['composite'],
    'user price memory' => ['price memory'],
    'production batch ingredient' => ['production batch'],
]);

it('allows only administrators to delete platform ingredients', function () {
    $customer = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);

    expect(fn () => app(PlatformIngredientDeletionService::class)->delete($customer, $ingredient))
        ->toThrow(AuthorizationException::class);

    $this->assertModelExists($ingredient);
});

it('refuses to delete private user ingredients through the platform operation', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    expect(fn () => app(PlatformIngredientDeletionService::class)->delete($admin, $ingredient))
        ->toThrow(ValidationException::class);

    $this->assertModelExists($ingredient);
});

it('keeps deactivated platform ingredients attached to existing formulas', function () {
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $recipeItem = RecipeItem::factory()->create(['ingredient_id' => $ingredient->id]);

    $ingredient->update(['is_active' => false]);

    expect($recipeItem->fresh()->ingredient)
        ->not->toBeNull()
        ->id->toBe($ingredient->id)
        ->and($ingredient->fresh()->is_active)->toBeFalse();
});
