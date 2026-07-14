<?php

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

it('moves legacy public recipe media into recipe-specific private namespaces', function () {
    Storage::fake('public');
    Storage::fake('local');
    config([
        'media.disk' => 'public',
        'media.recipe_disk' => 'local',
    ]);

    $sharedPath = 'recipes/rich-content/shared.webp';
    $featuredPath = 'recipes/featured-images/product.webp';
    $firstRecipe = Recipe::factory()->create([
        'featured_image_path' => $featuredPath,
        'description' => '<p><img data-id="'.$sharedPath.'"></p>',
    ]);
    $secondRecipe = Recipe::factory()->create([
        'manufacturing_instructions' => '<p><img data-id="'.$sharedPath.'"></p>',
    ]);
    Storage::disk('public')->put($sharedPath, 'shared');
    Storage::disk('public')->put($featuredPath, 'featured');

    artisan('app:migrate-recipe-media-to-private')
        ->assertSuccessful();

    $firstFeaturedPath = 'recipes/'.$firstRecipe->public_id.'/featured-images/product.webp';
    $firstSharedPath = 'recipes/'.$firstRecipe->public_id.'/rich-content/shared.webp';
    $secondSharedPath = 'recipes/'.$secondRecipe->public_id.'/rich-content/shared.webp';

    expect($firstRecipe->fresh()->featured_image_path)->toBe($firstFeaturedPath)
        ->and($firstRecipe->fresh()->description)->toContain($firstSharedPath)
        ->and($secondRecipe->fresh()->manufacturing_instructions)->toContain($secondSharedPath)
        ->and(Storage::disk('local')->get($firstFeaturedPath))->toBe('featured')
        ->and(Storage::disk('local')->get($firstSharedPath))->toBe('shared')
        ->and(Storage::disk('local')->get($secondSharedPath))->toBe('shared')
        ->and(Storage::disk('public')->exists($featuredPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($sharedPath))->toBeFalse();

    artisan('app:migrate-recipe-media-to-private')
        ->assertSuccessful();
});

it('fails without changing references when an existing private target has different content', function () {
    Storage::fake('public');
    Storage::fake('local');
    config([
        'media.disk' => 'public',
        'media.recipe_disk' => 'local',
    ]);

    $legacyPath = 'recipes/featured-images/product.webp';
    $recipe = Recipe::factory()->create([
        'featured_image_path' => $legacyPath,
    ]);
    $targetPath = 'recipes/'.$recipe->public_id.'/featured-images/product.webp';
    Storage::disk('public')->put($legacyPath, 'valid-source');
    Storage::disk('local')->put($targetPath, 'stale-target');

    artisan('app:migrate-recipe-media-to-private')
        ->assertFailed();

    expect($recipe->fresh()->featured_image_path)->toBe($legacyPath)
        ->and(Storage::disk('public')->get($legacyPath))->toBe('valid-source')
        ->and(Storage::disk('local')->get($targetPath))->toBe('stale-target');
});

it('refuses identical public and private disks without deleting namespaced media', function () {
    Storage::fake('public');
    config([
        'media.disk' => 'public',
        'media.recipe_disk' => 'public',
    ]);

    $recipe = Recipe::factory()->create();
    $path = 'recipes/'.$recipe->public_id.'/featured-images/private.webp';
    $recipe->update(['featured_image_path' => $path]);
    Storage::disk('public')->put($path, 'private-image');

    artisan('app:migrate-recipe-media-to-private')
        ->assertFailed();

    expect($recipe->fresh()->featured_image_path)->toBe($path)
        ->and(Storage::disk('public')->get($path))->toBe('private-image');
});
