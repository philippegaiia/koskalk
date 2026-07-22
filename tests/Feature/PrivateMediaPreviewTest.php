<?php

use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Visibility;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'media.recipe_disk' => 'local',
        'media.user_disk' => 'local',
    ]);

    Storage::fake('local');
});

it('loads a private recipe image preview through its authenticated application route', function () {
    $owner = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
        'featured_image_path' => null,
    ]);
    $path = MediaStorage::recipeDirectory($recipe, 'featured-images').'/soap.webp';
    $recipe->update(['featured_image_path' => $path]);
    Storage::disk('local')->put($path, 'private-image');

    $this->actingAs($owner);

    $field = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->instance()
        ->form
        ->getComponent('featured_image_path');

    expect($field)->toBeInstanceOf(FileUpload::class)
        ->and(array_values($field->getUploadedFiles())[0]['url'])->toBe(route('recipes.media', [
            'recipe' => $recipe,
            'path' => $path,
        ]));
});

it('loads a private ingredient image preview through its authenticated application route', function () {
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
        'featured_image_path' => null,
    ]);
    $path = MediaStorage::ingredientDirectory($ingredient, 'featured-images').'/sodium-citrate.webp';
    $ingredient->update(['featured_image_path' => $path]);
    Storage::disk('local')->put($path, 'private-image');

    $this->actingAs($owner);

    $field = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->instance()
        ->form
        ->getComponent('featured_image_path');

    expect($field)->toBeInstanceOf(FileUpload::class)
        ->and(array_values($field->getUploadedFiles())[0]['url'])->toBe(route('ingredients.media', [
            'ingredient' => $ingredient,
            'path' => $path,
        ]));
});

it('loads a private packaging image preview through its authenticated application route', function () {
    $owner = User::factory()->create();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1.25,
        'currency' => 'EUR',
    ]);
    $path = MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/carton.webp';
    $packagingItem->update(['featured_image_path' => $path]);
    Storage::disk('local')->put($path, 'private-image');

    $this->actingAs($owner);

    $field = Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packagingItem])
        ->instance()
        ->form
        ->getComponent('featured_image_path');

    expect($field)->toBeInstanceOf(FileUpload::class)
        ->and(array_values($field->getUploadedFiles())[0]['url'])->toBe(route('packaging-items.media', [
            'packagingItem' => $packagingItem,
            'path' => $path,
        ]));
});

it('keeps original upload names in the private media form state', function () {
    $owner = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
    ]);
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1.25,
        'currency' => 'EUR',
    ]);

    $this->actingAs($owner);

    $recipeField = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->instance()
        ->form
        ->getComponent('featured_image_path');
    $ingredientFeaturedField = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->instance()
        ->form
        ->getComponent('featured_image_path');
    $ingredientIconField = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->instance()
        ->form
        ->getComponent('icon_image_path');
    $packagingField = Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packagingItem])
        ->instance()
        ->form
        ->getComponent('featured_image_path');

    expect($recipeField)->toBeInstanceOf(FileUpload::class)
        ->and($recipeField->getFileNamesStatePath())->toBeString()->toEndWith('featured_image_original_name')
        ->and($ingredientFeaturedField)->toBeInstanceOf(FileUpload::class)
        ->and($ingredientFeaturedField->getFileNamesStatePath())->toBeString()->toEndWith('featured_image_original_name')
        ->and($ingredientIconField)->toBeInstanceOf(FileUpload::class)
        ->and($ingredientIconField->getFileNamesStatePath())->toBeString()->toEndWith('icon_image_original_name')
        ->and($packagingField)->toBeInstanceOf(FileUpload::class)
        ->and($packagingField->getFileNamesStatePath())->toBeString()->toEndWith('featured_image_original_name');
});

it('uses a neutral display name for legacy private uploads and restores a saved original name', function () {
    $owner = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
    ]);
    $recipePath = MediaStorage::recipeDirectory($recipe, 'featured-images').'/01JQ0RANDOM.webp';
    $recipe->update(['featured_image_path' => $recipePath]);
    Storage::disk('local')->put($recipePath, 'private-image');

    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);
    $ingredientPath = MediaStorage::ingredientDirectory($ingredient, 'featured-images').'/01JQ1RANDOM.webp';
    $ingredient->update([
        'featured_image_path' => $ingredientPath,
        'featured_image_original_name' => 'Sérum visage.png',
    ]);
    Storage::disk('local')->put($ingredientPath, 'private-image');

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1.25,
        'currency' => 'EUR',
    ]);
    $packagingPath = MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/01JQ2RANDOM.webp';
    $packagingItem->update(['featured_image_path' => $packagingPath]);
    Storage::disk('local')->put($packagingPath, 'private-image');

    $this->actingAs($owner);

    $recipeField = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->instance()
        ->form
        ->getComponent('featured_image_path');
    $ingredientField = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient->fresh()])
        ->instance()
        ->form
        ->getComponent('featured_image_path');
    $packagingField = Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packagingItem])
        ->instance()
        ->form
        ->getComponent('featured_image_path');

    expect(array_values($recipeField->getUploadedFiles())[0]['name'])->toBe(__('media.current_image'))
        ->and(array_values($recipeField->getUploadedFiles())[0]['name'])->not->toBe(basename($recipePath))
        ->and(array_values($ingredientField->getUploadedFiles())[0]['name'])->toBe('Sérum visage.png')
        ->and(array_values($packagingField->getUploadedFiles())[0]['name'])->toBe(__('media.current_image'))
        ->and(array_values($packagingField->getUploadedFiles())[0]['name'])->not->toBe(basename($packagingPath));
});
