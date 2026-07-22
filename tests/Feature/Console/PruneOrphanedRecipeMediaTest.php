<?php

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\MediaStorage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-22 12:00:00 UTC');
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('deletes only old unreferenced media in recipe image namespaces', function (): void {
    $recipe = Recipe::factory()->create();
    $referencedPath = MediaStorage::recipeDirectory($recipe, 'rich-content').'/referenced.webp';
    $featuredImagePath = MediaStorage::recipeDirectory($recipe, 'featured-images').'/active.webp';
    $oldUnreferencedPath = MediaStorage::recipeDirectory($recipe, 'featured-images').'/orphaned.webp';
    $recentUnreferencedPath = MediaStorage::recipeDirectory($recipe, 'rich-content').'/recent.webp';
    $otherRecipeNamespacePath = 'recipes/'.$recipe->public_id.'/documents/never-scanned.pdf';
    $invalidRecipeNamespacePath = 'recipes/not-a-uuid/featured-images/invalid.webp';
    $missingRecipePath = 'recipes/'.Str::uuid().'/rich-content/missing-recipe.webp';
    $ingredientPath = 'ingredients/'.Str::uuid().'/featured-images/ingredient.webp';
    $packagingPath = 'packaging-items/'.Str::uuid().'/featured-images/packaging.webp';

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$referencedPath.'"></p>',
    ]);
    $recipe->update(['featured_image_path' => $featuredImagePath]);

    putRecipePruneTestFile($referencedPath, now()->subHours(25));
    putRecipePruneTestFile($featuredImagePath, now()->subHours(25));
    putRecipePruneTestFile($oldUnreferencedPath, now()->subHours(25));
    putRecipePruneTestFile($recentUnreferencedPath, now()->subHours(23));
    putRecipePruneTestFile($otherRecipeNamespacePath, now()->subHours(25));
    putRecipePruneTestFile($invalidRecipeNamespacePath, now()->subHours(25));
    putRecipePruneTestFile($missingRecipePath, now()->subHours(25));
    putRecipePruneTestFile($ingredientPath, now()->subHours(25));
    putRecipePruneTestFile($packagingPath, now()->subHours(25));

    $exitCode = Artisan::call('media:prune-orphaned-recipe', ['--age' => 24]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Scanned: 5')
        ->and($output)->toContain('Deleted: 2')
        ->and($output)->toContain('Preserved: 3')
        ->and($output)->not->toContain('orphaned.webp')
        ->and($output)->not->toContain('missing-recipe.webp');

    Storage::disk('local')->assertExists([
        $referencedPath,
        $featuredImagePath,
        $recentUnreferencedPath,
        $otherRecipeNamespacePath,
        $invalidRecipeNamespacePath,
        $ingredientPath,
        $packagingPath,
    ]);
    Storage::disk('local')->assertMissing([
        $oldUnreferencedPath,
        $missingRecipePath,
    ]);
});

it('rejects an age below one hour without deleting media', function (): void {
    $recipe = Recipe::factory()->create();
    $path = MediaStorage::recipeDirectory($recipe, 'featured-images').'/too-young-option.webp';
    putRecipePruneTestFile($path, now()->subDays(2));

    $this->artisan('media:prune-orphaned-recipe', ['--age' => 0])
        ->expectsOutputToContain('at least 1')
        ->doesntExpectOutputToContain($path)
        ->assertFailed();

    Storage::disk('local')->assertExists($path);
});

it('schedules orphaned recipe media pruning daily without overlap', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'media:prune-orphaned-recipe'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function putRecipePruneTestFile(string $path, Carbon $modifiedAt): void
{
    $disk = Storage::disk('local');
    $disk->put($path, 'test-media');
    touch($disk->path($path), $modifiedAt->getTimestamp());
    clearstatcache(true, $disk->path($path));
}
