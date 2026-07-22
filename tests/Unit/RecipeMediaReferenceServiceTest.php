<?php

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\MediaStorage;
use App\Services\RecipeMediaReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);
});

it('returns the unique union of current content and every retained SOP snapshot', function (): void {
    $recipe = Recipe::factory()->create();
    $directory = MediaStorage::recipeDirectory($recipe, 'rich-content');
    $descriptionPath = $directory.'/description.webp';
    $currentSopPath = $directory.'/current-sop.webp';
    $currentVersionOnlyPath = $directory.'/current-version.webp';
    $historicalPath = $directory.'/historical.webp';

    $recipe->update([
        'description' => '<p><img data-id="'.$descriptionPath.'"></p>',
        'manufacturing_instructions' => '<p><img data-id="'.$currentSopPath.'"></p>',
        'featured_image_path' => MediaStorage::recipeDirectory($recipe, 'featured-images').'/featured.webp',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => true,
        'manufacturing_instructions' => '<p><img data-id="'.$currentSopPath.'"><img data-id="'.$currentVersionOnlyPath.'"></p>',
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 2,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$historicalPath.'"><img data-id="'.$descriptionPath.'"></p>',
    ]);

    expect(app(RecipeMediaReferenceService::class)->allReferencedPaths($recipe)->all())
        ->toBe([
            $descriptionPath,
            $currentSopPath,
            $currentVersionOnlyPath,
            $historicalPath,
        ]);
});

it('returns SOP paths from retained non-current versions only', function (): void {
    $recipe = Recipe::factory()->create();
    $directory = MediaStorage::recipeDirectory($recipe, 'rich-content');
    $currentPath = $directory.'/current.webp';
    $historicalPath = $directory.'/historical.webp';

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => true,
        'manufacturing_instructions' => '<p><img data-id="'.$currentPath.'"></p>',
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 2,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$historicalPath.'"></p>',
    ]);

    expect(app(RecipeMediaReferenceService::class)->historicalSopPaths($recipe)->all())
        ->toBe([$historicalPath]);
});

it('deletes only candidates absent from a fresh reference union', function (): void {
    $recipe = Recipe::factory()->create();
    $directory = MediaStorage::recipeDirectory($recipe, 'rich-content');
    $referencedPath = $directory.'/referenced.webp';
    $orphanedPath = $directory.'/orphaned.webp';

    Storage::disk('local')->put($referencedPath, 'referenced');
    Storage::disk('local')->put($orphanedPath, 'orphaned');

    Recipe::withoutGlobalScopes()->whereKey($recipe->id)->update([
        'description' => '<p><img data-id="'.$referencedPath.'"></p>',
    ]);

    app(RecipeMediaReferenceService::class)->deleteIfUnreferenced(
        $recipe,
        collect([$referencedPath, $orphanedPath, $orphanedPath]),
    );

    expect(Storage::disk('local')->exists($referencedPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($orphanedPath))->toBeFalse();
});
