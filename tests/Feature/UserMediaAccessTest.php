<?php

use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\OwnerType;
use App\Services\IngredientFormulaMutationService;
use App\Services\MediaStorage;
use App\Services\UserPackagingItemAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'media.disk' => 'public',
        'media.user_disk' => 'local',
    ]);

    Storage::fake('public');
    Storage::fake('local');
});

it('keeps platform catalog media public and user ingredient media private', function () {
    $owner = User::factory()->create();
    $platformIngredient = Ingredient::factory()->create([
        'featured_image_path' => 'catalog/ingredients/platform.webp',
    ]);
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
        'featured_image_path' => null,
    ]);
    $privatePath = MediaStorage::ingredientDirectory($privateIngredient, 'featured-images').'/private.webp';
    $privateIngredient->update(['featured_image_path' => $privatePath]);

    Storage::disk('public')->put($platformIngredient->featured_image_path, 'catalog-image');
    Storage::disk('local')->put($privatePath, 'private-image');

    expect($platformIngredient->featuredImageUrl())->toContain('/storage/catalog/ingredients/platform.webp')
        ->and($privateIngredient->fresh()->featuredImageUrl())->toBe(route('ingredients.media', [
            'ingredient' => $privateIngredient,
            'path' => $privatePath,
        ]));

    $response = $this->actingAs($owner)
        ->get($privateIngredient->fresh()->featuredImageUrl())
        ->assertOk();

    expect($response->streamedContent())->toBe('private-image');

    $this->actingAs(User::factory()->create())
        ->get($privateIngredient->fresh()->featuredImageUrl())
        ->assertNotFound();
});

it('serves packaging media only to its owner', function () {
    $owner = User::factory()->create();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1.25,
        'currency' => 'EUR',
    ]);
    $path = MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/private.webp';
    $packagingItem->featured_image_path = $path;
    $packagingItem->save();
    Storage::disk('local')->put($path, 'packaging-image');

    $mediaUrl = $packagingItem->fresh()->featuredImageUrl();

    expect($mediaUrl)->toBe(route('packaging-items.media', [
        'packagingItem' => $packagingItem,
        'path' => $path,
    ]));

    $response = $this->actingAs($owner)->get($mediaUrl)->assertOk();

    expect($response->streamedContent())->toBe('packaging-image');

    $this->actingAs(User::factory()->create())
        ->get($mediaUrl)
        ->assertNotFound();
});

it('rejects unreferenced and cross-record user media paths', function () {
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);
    $otherIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);
    $otherPath = MediaStorage::ingredientDirectory($otherIngredient, 'featured-images').'/other.webp';
    $otherIngredient->update(['featured_image_path' => $otherPath]);
    Storage::disk('local')->put($otherPath, 'other-image');

    $this->actingAs($owner)
        ->get(route('ingredients.media', [
            'ingredient' => $ingredient,
            'path' => $otherPath,
        ]))
        ->assertNotFound();
});

it('deletes complete private media prefixes after user records are deleted', function () {
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);
    $ingredientPath = MediaStorage::ingredientDirectory($ingredient, 'featured-images').'/referenced.webp';
    $ingredientOrphan = MediaStorage::ingredientDirectory($ingredient, 'icons').'/orphan.webp';
    $ingredient->update(['featured_image_path' => $ingredientPath]);
    Storage::disk('local')->put($ingredientPath, 'ingredient');
    Storage::disk('local')->put($ingredientOrphan, 'orphan');

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Disposable carton',
        'unit_cost' => 1,
        'currency' => 'EUR',
    ]);
    $packagingPath = MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/referenced.webp';
    $packagingOrphan = MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/orphan.webp';
    $packagingItem->update(['featured_image_path' => $packagingPath]);
    Storage::disk('local')->put($packagingPath, 'packaging');
    Storage::disk('local')->put($packagingOrphan, 'orphan');

    app(IngredientFormulaMutationService::class)->removeEverywhereAndDelete($owner, $ingredient);
    expect(app(UserPackagingItemAuthoringService::class)->delete($packagingItem, $owner))->toBeTrue();

    expect(Storage::disk('local')->allFiles('ingredients/'.$ingredient->public_id))->toBe([])
        ->and(Storage::disk('local')->allFiles('packaging-items/'.$packagingItem->public_id))->toBe([]);
});
