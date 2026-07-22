<?php

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\MediaStorage;
use App\Services\RecipeMediaRollbackGuard;
use App\Services\RecipeRichContentAttachmentProvider;
use App\Services\RecipeVersionDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('cleans new recipe media when the guarded transaction boundary throws', function () {
    Storage::fake(MediaStorage::recipeDisk());

    $recipe = Recipe::factory()->create();
    $path = MediaStorage::recipeDirectory($recipe, 'rich-content').'/rolled-back.webp';
    Storage::disk(MediaStorage::recipeDisk())->put($path, 'rolled-back-image');

    expect(fn () => app(RecipeMediaRollbackGuard::class)->run(
        true,
        fn (): Recipe => $recipe,
        fn () => throw new RuntimeException('Transaction boundary failed.'),
    ))->toThrow(RuntimeException::class, 'Transaction boundary failed.')
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($path))->toBeFalse();
});

it('stores recipe images as webp within the configured bounds', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $file = UploadedFile::fake()->image('recipe.jpg', 1600, 900);

    $path = MediaStorage::storeResizedWebp($file, 'recipes/featured-images', 800, 600, 85);
    $image = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBeLessThanOrEqual(800)
        ->and($image[1] ?? null)->toBeLessThanOrEqual(600);
});

it('stores ingredient icons as exact 96x96 webp images', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $file = UploadedFile::fake()->image('icon.jpg', 320, 200);

    $path = MediaStorage::storeFittedWebp($file, 'ingredients/icons', 96, 96, 85);
    $image = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBe(96)
        ->and($image[1] ?? null)->toBe(96);
});

it('stores shared catalog images as exact 400x400 webp images', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $file = UploadedFile::fake()->image('catalog.jpg', 1200, 900);

    $path = MediaStorage::storeFittedWebp(
        $file,
        'catalog/featured-images',
        MediaStorage::ingredientImageWidth(),
        MediaStorage::ingredientImageHeight(),
        MediaStorage::ingredientImagesQuality(),
    );
    $image = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBe(400)
        ->and($image[1] ?? null)->toBe(400);
});

it('returns null for missing public media paths', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    expect(MediaStorage::publicUrl('ingredients/icons/missing.webp'))->toBeNull();
});

it('omits unsupported object ACL options for R2 disks', function () {
    config([
        'filesystems.disks.r2_public.supports_visibility' => false,
        'filesystems.disks.r2_private.supports_visibility' => false,
    ]);

    expect(MediaStorage::writeOptions('r2_public', 'public', 'image/webp'))
        ->toBe(['ContentType' => 'image/webp'])
        ->and(MediaStorage::writeOptions('r2_private', 'private'))
        ->toBe([])
        ->and(MediaStorage::writeOptions('local', 'private'))
        ->toBe(['visibility' => 'private']);
});

it('stores rich content images as bounded webp attachments without cropping', function () {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $file = UploadedFile::fake()->image('inline.jpg', 1200, 600);
    $recipe = Recipe::factory()->create();

    $path = app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->saveUploadedFileAttachment($file);
    $image = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($path)->toStartWith('recipes/'.$recipe->public_id.'/rich-content/')
        ->and($path)->toEndWith('.webp')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(MediaStorage::recipeVisibility())->toBe('private')
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBeLessThanOrEqual(680)
        ->and($image[1] ?? null)->toBeLessThanOrEqual(340)
        ->and(($image[0] ?? 0) / ($image[1] ?? 1))->toBeGreaterThan(1.99)
        ->and(($image[0] ?? 0) / ($image[1] ?? 1))->toBeLessThan(2.01);
});

it('stores featured recipe images within their configured bounds without cropping', function (): void {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $file = UploadedFile::fake()->image('featured.jpg', 600, 1200);
    $path = MediaStorage::storeRecipeResizedWebp(
        $file,
        'recipes/featured-images',
        MediaStorage::recipeFeaturedImagesWidth(),
        MediaStorage::recipeFeaturedImagesHeight(),
        MediaStorage::recipeFeaturedImagesQuality(),
    );
    $image = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBeLessThanOrEqual(400)
        ->and($image[1] ?? null)->toBeLessThanOrEqual(800)
        ->and(($image[0] ?? 0) / ($image[1] ?? 1))->toBeGreaterThan(0.49)
        ->and(($image[0] ?? 0) / ($image[1] ?? 1))->toBeLessThan(0.51);
});

it('never upscales valid recipe images', function (): void {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $file = UploadedFile::fake()->image('featured.jpg', 500, 300);
    $path = MediaStorage::storeRecipeResizedWebp(
        $file,
        'recipes/featured-images',
        MediaStorage::recipeFeaturedImagesWidth(),
        MediaStorage::recipeFeaturedImagesHeight(),
        MediaStorage::recipeFeaturedImagesQuality(),
    );
    $image = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($image)->not->toBeFalse()
        ->and($image[0] ?? null)->toBe(500)
        ->and($image[1] ?? null)->toBe(300);
});

it('converts JPEG, PNG, and WebP recipe image uploads to WebP', function (string $extension): void {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $file = UploadedFile::fake()->image('recipe.'.$extension, 600, 300);
    $path = MediaStorage::storeRecipeResizedWebp($file, 'recipes/featured-images', 800, 800, 82);
    $image = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($path)->toEndWith('.webp')
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp');
})->with(['jpg', 'png', 'webp']);

it('cleans up recipe rich content attachments that are no longer referenced', function () {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create([
        'description' => '<p><img data-id="recipes/rich-content/keep.webp" src="/storage/recipes/rich-content/keep.webp"><img data-id="recipes/rich-content/remove.webp" src="/storage/recipes/rich-content/remove.webp"></p>',
    ]);

    Storage::disk('local')->put('recipes/rich-content/keep.webp', 'keep');
    Storage::disk('local')->put('recipes/rich-content/remove.webp', 'remove');

    app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->cleanUpFileAttachments(['recipes/rich-content/keep.webp']);

    expect(Storage::disk('local')->exists('recipes/rich-content/keep.webp'))->toBeTrue()
        ->and(Storage::disk('local')->exists('recipes/rich-content/remove.webp'))->toBeFalse();
});

it('preserves shared rich content attachments that are still referenced by the other recipe editor', function () {
    Storage::fake('local');

    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create([
        'description' => '<p><img data-id="recipes/rich-content/keep.webp" src="/storage/recipes/rich-content/keep.webp"><img data-id="recipes/rich-content/shared.webp" src="/storage/recipes/rich-content/shared.webp"><img data-id="recipes/rich-content/remove.webp" src="/storage/recipes/rich-content/remove.webp"></p>',
        'manufacturing_instructions' => '<p><img data-id="recipes/rich-content/shared.webp" src="/storage/recipes/rich-content/shared.webp"></p>',
    ]);

    Storage::disk('local')->put('recipes/rich-content/keep.webp', 'keep');
    Storage::disk('local')->put('recipes/rich-content/shared.webp', 'shared');
    Storage::disk('local')->put('recipes/rich-content/remove.webp', 'remove');

    app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->cleanUpFileAttachments(['recipes/rich-content/keep.webp']);

    expect(Storage::disk('local')->exists('recipes/rich-content/keep.webp'))->toBeTrue()
        ->and(Storage::disk('local')->exists('recipes/rich-content/shared.webp'))->toBeTrue()
        ->and(Storage::disk('local')->exists('recipes/rich-content/remove.webp'))->toBeFalse();
});

it('preserves attachments referenced by a retained historical SOP during editor cleanup', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create();
    $path = MediaStorage::recipeDirectory($recipe, 'rich-content').'/historical.webp';
    $html = '<p><img data-id="'.$path.'"></p>';
    $recipe->update(['description' => $html]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => false,
        'manufacturing_instructions' => $html,
    ]);
    Storage::disk('local')->put($path, 'historical-image');

    app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->cleanUpFileAttachments([]);

    expect(Storage::disk('local')->exists($path))->toBeTrue();
});

it('deletes an attachment after the last saved SOP referencing it is deleted', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create();
    $path = MediaStorage::recipeDirectory($recipe, 'rich-content').'/last-snapshot.webp';
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$path.'"></p>',
    ]);
    Storage::disk('local')->put($path, 'last-snapshot-image');

    app(RecipeVersionDeletionService::class)->delete($recipe, $version);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('deletes only newly orphaned attachments when old recovery snapshots are pruned', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create();
    $directory = MediaStorage::recipeDirectory($recipe, 'rich-content');
    $orphanedPath = $directory.'/pruned-only.webp';
    $sharedPath = $directory.'/shared-with-retained.webp';

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$orphanedPath.'"><img data-id="'.$sharedPath.'"></p>',
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 2,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$sharedPath.'"></p>',
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 3,
        'is_current' => false,
        'manufacturing_instructions' => '<p>Newest saved SOP.</p>',
    ]);
    Storage::disk('local')->put($orphanedPath, 'orphaned-after-prune');
    Storage::disk('local')->put($sharedPath, 'still-referenced');

    app(RecipeVersionDeletionService::class)->pruneHiddenRecoverySnapshots($recipe, 2);

    expect(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', false)->count())->toBe(2)
        ->and(Storage::disk('local')->exists($orphanedPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($sharedPath))->toBeTrue();
});

it('does not delete an attachment when version deletion is rolled back', function (): void {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create();
    $path = MediaStorage::recipeDirectory($recipe, 'rich-content').'/rolled-back-deletion.webp';
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'version_number' => 1,
        'is_current' => false,
        'manufacturing_instructions' => '<p><img data-id="'.$path.'"></p>',
    ]);
    Storage::disk('local')->put($path, 'must-survive');

    expect(fn () => DB::transaction(function () use ($recipe, $version): void {
        app(RecipeVersionDeletionService::class)->delete($recipe, $version);

        throw new RuntimeException('Force rollback.');
    }))->toThrow(RuntimeException::class, 'Force rollback.');

    expect(RecipeVersion::withoutGlobalScopes()->find($version->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('auto orients jpeg uploads before converting them to webp', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $fixturePath = createExifOrientedJpegFixture(40, 20, 6);
    $file = new UploadedFile($fixturePath, 'oriented.jpg', 'image/jpeg', null, true);

    $path = MediaStorage::storeResizedWebp($file, 'recipes/rich-content', 1600, 1600, 85);
    $image = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($path)->toEndWith('.webp')
        ->and($image)->not->toBeFalse()
        ->and($image[0] ?? null)->toBe(20)
        ->and($image[1] ?? null)->toBe(40);
});

function createExifOrientedJpegFixture(int $width, int $height, int $orientation): string
{
    $path = tempnam(sys_get_temp_dir(), 'orient');

    if (! is_string($path)) {
        throw new RuntimeException('Unable to create temp file for EXIF orientation test.');
    }

    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Unable to create GD image for EXIF orientation test.');
    }

    $background = imagecolorallocate($image, 255, 0, 0);
    imagefill($image, 0, 0, $background);
    imagejpeg($image, $path, 90);

    $jpeg = file_get_contents($path);

    if ($jpeg === false) {
        throw new RuntimeException('Unable to read generated JPEG for EXIF orientation test.');
    }

    file_put_contents($path, withExifOrientation($jpeg, $orientation));

    return $path;
}

function withExifOrientation(string $jpeg, int $orientation): string
{
    if (! str_starts_with($jpeg, "\xFF\xD8")) {
        throw new RuntimeException('Generated test fixture is not a JPEG image.');
    }

    $exifPayload = "Exif\0\0"
        ."II*\0"
        .pack('V', 8)
        .pack('v', 1)
        .pack('v', 0x0112)
        .pack('v', 3)
        .pack('V', 1)
        .pack('v', $orientation)
        ."\0\0"
        .pack('V', 0);

    $segment = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}
