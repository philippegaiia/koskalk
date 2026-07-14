<?php

use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\OwnerType;
use App\Services\MediaStorage;
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

it('moves only user-owned catalog media to namespaced private storage', function () {
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
        'featured_image_path' => 'ingredients/featured-images/private.webp',
        'icon_image_path' => 'ingredients/icons/private.webp',
    ]);
    $platformIngredient = Ingredient::factory()->create([
        'featured_image_path' => 'ingredients/featured-images/platform.webp',
    ]);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $owner->id,
        'name' => 'Private bottle',
        'unit_cost' => 2,
        'currency' => 'EUR',
        'featured_image_path' => 'packaging/featured-images/private.webp',
    ]);

    Storage::disk('public')->put($ingredient->featured_image_path, 'ingredient');
    Storage::disk('public')->put($ingredient->icon_image_path, 'icon');
    Storage::disk('public')->put($platformIngredient->featured_image_path, 'platform');
    Storage::disk('public')->put($packagingItem->featured_image_path, 'packaging');

    $this->artisan('app:migrate-user-media-to-private')->assertSuccessful();

    $ingredient->refresh();
    $packagingItem->refresh();

    expect($ingredient->featured_image_path)->toStartWith(MediaStorage::ingredientDirectory($ingredient, 'featured-images').'/')
        ->and($ingredient->icon_image_path)->toStartWith(MediaStorage::ingredientDirectory($ingredient, 'icons').'/')
        ->and($packagingItem->featured_image_path)->toStartWith(MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/')
        ->and(Storage::disk('local')->get($ingredient->featured_image_path))->toBe('ingredient')
        ->and(Storage::disk('local')->get($ingredient->icon_image_path))->toBe('icon')
        ->and(Storage::disk('local')->get($packagingItem->featured_image_path))->toBe('packaging')
        ->and(Storage::disk('public')->exists('ingredients/featured-images/private.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/private.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('packaging/featured-images/private.webp'))->toBeFalse()
        ->and(Storage::disk('public')->get($platformIngredient->featured_image_path))->toBe('platform');

    $this->artisan('app:migrate-user-media-to-private')->assertSuccessful();
});

it('does not change database references when a legacy source is missing', function () {
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
        'featured_image_path' => 'ingredients/featured-images/missing.webp',
    ]);

    $this->artisan('app:migrate-user-media-to-private')->assertFailed();

    expect($ingredient->fresh()->featured_image_path)->toBe('ingredients/featured-images/missing.webp');
});
