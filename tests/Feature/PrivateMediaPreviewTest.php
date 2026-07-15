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
