<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaStorage
{
    public static function publicDisk(): string
    {
        $disk = (string) config('media.disk', config('filesystems.default', 'public'));

        if ($disk === 'local') {
            return 'public';
        }

        return $disk;
    }

    public static function publicVisibility(): string
    {
        return (string) config('media.visibility', 'public');
    }

    public static function recipeFeaturedImagesMaxSize(): int
    {
        return (int) config('media.recipe_featured_images.max_size_kb', 3072);
    }

    public static function recipeRichContentImagesMaxSize(): int
    {
        return (int) config('media.recipe_rich_content_images.max_size_kb', 1536);
    }

    public static function recipeRichContentImagesWidth(): int
    {
        return (int) config('media.recipe_rich_content_images.max_width', 1600);
    }

    public static function recipeRichContentImagesHeight(): int
    {
        return (int) config('media.recipe_rich_content_images.max_height', 1600);
    }

    public static function recipeRichContentImagesQuality(): int
    {
        return (int) config('media.recipe_rich_content_images.quality', 85);
    }

    public static function ingredientImagesMaxSize(): int
    {
        return (int) config('media.ingredient_images.max_size_kb', 2048);
    }

    public static function ingredientIconsMaxSize(): int
    {
        return (int) config('media.ingredient_icons.max_size_kb', 1024);
    }

    public static function ingredientImageWidth(): int
    {
        return (int) config('media.ingredient_images.width', 800);
    }

    public static function ingredientImageHeight(): int
    {
        return (int) config('media.ingredient_images.height', 800);
    }

    public static function ingredientIconsWidth(): int
    {
        return (int) config('media.ingredient_icons.width', 96);
    }

    public static function ingredientIconsHeight(): int
    {
        return (int) config('media.ingredient_icons.height', 96);
    }

    public static function recipeFeaturedImagesQuality(): int
    {
        return (int) config('media.recipe_featured_images.quality', 85);
    }

    public static function ingredientImagesQuality(): int
    {
        return (int) config('media.ingredient_images.quality', 85);
    }

    public static function ingredientIconsQuality(): int
    {
        return (int) config('media.ingredient_icons.quality', 85);
    }

    public static function storeResizedWebp(
        UploadedFile $file,
        string $directory,
        int $maxWidth,
        int $maxHeight,
        int $quality = 85,
    ): string {
        $path = trim($directory.'/'.Str::ulid().'.webp', '/');
        $binary = static::encodeWebp($file, $maxWidth, $maxHeight, $quality, false);

        Storage::disk(static::publicDisk())->put($path, $binary, [
            'visibility' => static::publicVisibility(),
            'ContentType' => 'image/webp',
        ]);

        return $path;
    }

    public static function storeFittedWebp(
        UploadedFile $file,
        string $directory,
        int $width,
        int $height,
        int $quality = 85,
    ): string {
        $path = trim($directory.'/'.Str::ulid().'.webp', '/');
        $binary = static::encodeWebp($file, $width, $height, $quality, true);

        Storage::disk(static::publicDisk())->put($path, $binary, [
            'visibility' => static::publicVisibility(),
            'ContentType' => 'image/webp',
        ]);

        return $path;
    }

    public static function publicUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(static::publicDisk())->url($path);
    }

    public static function deletePublicPath(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(static::publicDisk())->delete($path);
    }

    private static function encodeWebp(
        UploadedFile $file,
        int $targetWidth,
        int $targetHeight,
        int $quality,
        bool $fit,
    ): string {
        if (class_exists(\Imagick::class)) {
            return static::encodeWebpWithImagick($file, $targetWidth, $targetHeight, $quality, $fit);
        }

        if (function_exists('imagewebp')) {
            return static::encodeWebpWithGd($file, $targetWidth, $targetHeight, $quality, $fit);
        }

        throw new RuntimeException('WebP encoding requires Imagick or GD.');
    }

    private static function encodeWebpWithImagick(
        UploadedFile $file,
        int $targetWidth,
        int $targetHeight,
        int $quality,
        bool $fit,
    ): string {
        $image = new \Imagick($file->getRealPath());
        static::orientImagickImage($image);
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);

        if ($fit) {
            $image->cropThumbnailImage($targetWidth, $targetHeight);
        } else {
            if ($image->getImageWidth() > $targetWidth || $image->getImageHeight() > $targetHeight) {
                $image->thumbnailImage($targetWidth, $targetHeight, true, false);
            }
        }

        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);

        $binary = $image->getImagesBlob();

        $image->clear();
        $image->destroy();

        return $binary;
    }

    private static function encodeWebpWithGd(
        UploadedFile $file,
        int $targetWidth,
        int $targetHeight,
        int $quality,
        bool $fit,
    ): string {
        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('Unable to read uploaded image.');
        }

        $source = static::orientGdImage($source, $file);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($fit) {
            $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $resizeWidth = max(1, (int) ceil($sourceWidth * $scale));
            $resizeHeight = max(1, (int) ceil($sourceHeight * $scale));
            $destination = imagecreatetruecolor($targetWidth, $targetHeight);

            imagealphablending($destination, false);
            imagesavealpha($destination, true);

            $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
            imagefill($destination, 0, 0, $transparent);

            imagecopyresampled(
                $destination,
                $source,
                (int) floor(($targetWidth - $resizeWidth) / 2),
                (int) floor(($targetHeight - $resizeHeight) / 2),
                0,
                0,
                $resizeWidth,
                $resizeHeight,
                $sourceWidth,
                $sourceHeight,
            );
        } else {
            $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight, 1);
            $resizeWidth = max(1, (int) round($sourceWidth * $scale));
            $resizeHeight = max(1, (int) round($sourceHeight * $scale));
            $destination = imagecreatetruecolor($resizeWidth, $resizeHeight);

            imagealphablending($destination, false);
            imagesavealpha($destination, true);

            $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
            imagefill($destination, 0, 0, $transparent);

            imagecopyresampled(
                $destination,
                $source,
                0,
                0,
                0,
                0,
                $resizeWidth,
                $resizeHeight,
                $sourceWidth,
                $sourceHeight,
            );
        }

        ob_start();
        imagewebp($destination, null, $quality);
        $binary = (string) ob_get_clean();

        return $binary;
    }

    private static function orientImagickImage(\Imagick $image): void
    {
        $background = new \ImagickPixel('transparent');

        match ($image->getImageOrientation()) {
            \Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            \Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage($background, 180),
            \Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
            \Imagick::ORIENTATION_LEFTTOP => $image->transposeImage(),
            \Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage($background, 90),
            \Imagick::ORIENTATION_RIGHTBOTTOM => $image->transverseImage(),
            \Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage($background, -90),
            default => null,
        };

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    private static function orientGdImage(\GdImage $image, UploadedFile $file): \GdImage
    {
        $orientation = static::readExifOrientation($file);

        if ($orientation === null || $orientation === 1) {
            return $image;
        }

        return match ($orientation) {
            2 => static::flipGdImage($image, IMG_FLIP_HORIZONTAL),
            3 => static::rotateGdImage($image, 180),
            4 => static::flipGdImage($image, IMG_FLIP_VERTICAL),
            5 => static::rotateGdImage(static::flipGdImage($image, IMG_FLIP_VERTICAL), -90),
            6 => static::rotateGdImage($image, -90),
            7 => static::rotateGdImage(static::flipGdImage($image, IMG_FLIP_HORIZONTAL), -90),
            8 => static::rotateGdImage($image, 90),
            default => $image,
        };
    }

    private static function rotateGdImage(\GdImage $image, float $degrees): \GdImage
    {
        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $background);

        if ($rotated === false) {
            throw new RuntimeException('Unable to rotate uploaded image.');
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private static function flipGdImage(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private static function readExifOrientation(UploadedFile $file): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $mimeType = strtolower((string) $file->getMimeType());

        if ($mimeType !== 'image/jpeg' && $mimeType !== 'image/jpg') {
            return null;
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return null;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        return is_numeric($orientation) ? (int) $orientation : null;
    }
}
