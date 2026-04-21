<?php

use App\Models\Recipe;
use App\Services\MediaStorage;
use App\Services\RecipeRichContentAttachmentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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

it('returns null for missing public media paths', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    expect(MediaStorage::publicUrl('ingredients/icons/missing.webp'))->toBeNull();
});

it('stores rich content images as bounded webp attachments', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $file = UploadedFile::fake()->image('inline.jpg', 2800, 2200);

    $path = app(RecipeRichContentAttachmentProvider::class)->saveUploadedFileAttachment($file);
    $image = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($path)->toStartWith('recipes/rich-content/')
        ->and($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($image)->not->toBeFalse()
        ->and($image['mime'] ?? null)->toBe('image/webp')
        ->and($image[0] ?? null)->toBeLessThanOrEqual(1600)
        ->and($image[1] ?? null)->toBeLessThanOrEqual(1600);
});

it('cleans up recipe rich content attachments that are no longer referenced', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $recipe = Recipe::factory()->create([
        'description' => '<p><img data-id="recipes/rich-content/keep.webp" src="/storage/recipes/rich-content/keep.webp"><img data-id="recipes/rich-content/remove.webp" src="/storage/recipes/rich-content/remove.webp"></p>',
    ]);

    Storage::disk('public')->put('recipes/rich-content/keep.webp', 'keep');
    Storage::disk('public')->put('recipes/rich-content/remove.webp', 'remove');

    app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->cleanUpFileAttachments(['recipes/rich-content/keep.webp']);

    expect(Storage::disk('public')->exists('recipes/rich-content/keep.webp'))->toBeTrue()
        ->and(Storage::disk('public')->exists('recipes/rich-content/remove.webp'))->toBeFalse();
});

it('preserves shared rich content attachments that are still referenced by the other recipe editor', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $recipe = Recipe::factory()->create([
        'description' => '<p><img data-id="recipes/rich-content/keep.webp" src="/storage/recipes/rich-content/keep.webp"><img data-id="recipes/rich-content/shared.webp" src="/storage/recipes/rich-content/shared.webp"><img data-id="recipes/rich-content/remove.webp" src="/storage/recipes/rich-content/remove.webp"></p>',
        'manufacturing_instructions' => '<p><img data-id="recipes/rich-content/shared.webp" src="/storage/recipes/rich-content/shared.webp"></p>',
    ]);

    Storage::disk('public')->put('recipes/rich-content/keep.webp', 'keep');
    Storage::disk('public')->put('recipes/rich-content/shared.webp', 'shared');
    Storage::disk('public')->put('recipes/rich-content/remove.webp', 'remove');

    app(RecipeRichContentAttachmentProvider::class)
        ->attribute($recipe->getRichContentAttribute('description'))
        ->cleanUpFileAttachments(['recipes/rich-content/keep.webp']);

    expect(Storage::disk('public')->exists('recipes/rich-content/keep.webp'))->toBeTrue()
        ->and(Storage::disk('public')->exists('recipes/rich-content/shared.webp'))->toBeTrue()
        ->and(Storage::disk('public')->exists('recipes/rich-content/remove.webp'))->toBeFalse();
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
