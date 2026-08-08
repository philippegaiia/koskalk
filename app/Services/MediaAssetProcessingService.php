<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Exceptions\MediaAssetProcessingException;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver as ImageDriverEnum;
use Spatie\Image\Image;
use Throwable;

class MediaAssetProcessingService
{
    public function __construct(private readonly PdfPreviewRenderer $pdfPreviewRenderer) {}

    public function process(MediaAsset $asset, string $processingToken): void
    {
        if (
            $asset->status !== MediaAssetStatus::Processing
            || ! hash_equals($asset->processing_token, $processingToken)
        ) {
            return;
        }

        $asset->update([
            'progress' => 5,
            'processing_stage' => 'validating',
        ]);

        $sourcePath = $this->copyPendingFileToLocalPath($asset);
        $masterPath = null;

        try {
            if ($asset->type === MediaAssetType::Pdf) {
                $this->processPdf($asset, $sourcePath);

                return;
            }

            $this->assertCodecAvailable($asset);
            [$width, $height] = $this->dimensions($sourcePath);
            $this->assertPixelLimit($width, $height);

            $asset->update([
                'width' => $width,
                'height' => $height,
                'progress' => 25,
                'processing_stage' => 'normalizing',
            ]);

            $masterPath = $this->createMaster($sourcePath, $asset);
            [$masterWidth, $masterHeight] = $this->dimensions($masterPath);

            $asset->update([
                'width' => $masterWidth,
                'height' => $masterHeight,
                'progress' => 55,
                'processing_stage' => 'converting',
            ]);

            $asset->clearMediaCollection('master');
            $asset->addMedia($masterPath)
                ->usingName(pathinfo($asset->original_filename, PATHINFO_FILENAME))
                ->usingFileName(Str::uuid().'.webp')
                ->toMediaCollection('master', config('media.asset_disk'));
            $masterPath = null;

            $this->assertConversionsGenerated($asset);

            Storage::disk($asset->pending_disk)->delete($asset->pending_path);

            $asset->update([
                'status' => MediaAssetStatus::Ready,
                'pending_disk' => null,
                'pending_path' => null,
                'progress' => 100,
                'processing_stage' => null,
                'failure_code' => null,
                'failure_reason' => null,
            ]);
        } finally {
            @unlink($sourcePath);

            if ($masterPath !== null) {
                @unlink($masterPath);
            }
        }
    }

    public function markFailed(MediaAsset $asset, string $processingToken, Throwable $exception): void
    {
        $freshAsset = MediaAsset::query()->find($asset->id);

        if (
            ! $freshAsset instanceof MediaAsset
            || $freshAsset->status !== MediaAssetStatus::Processing
            || ! hash_equals($freshAsset->processing_token, $processingToken)
        ) {
            return;
        }

        $failureCode = $exception instanceof MediaAssetProcessingException
            ? $exception->failureCode
            : 'processing_failed';
        $failureReason = $exception instanceof MediaAssetProcessingException
            ? $exception->getMessage()
            : __('media_library.processing.failed');

        $freshAsset->update([
            'status' => MediaAssetStatus::Failed,
            'progress' => 0,
            'processing_stage' => null,
            'failure_code' => $failureCode,
            'failure_reason' => $failureReason,
        ]);
    }

    private function processPdf(MediaAsset $asset, string $sourcePath): void
    {
        $pageCount = $this->pdfPreviewRenderer->pageCount($sourcePath);
        $maxPages = (int) config('media.asset_uploads.pdf.max_pages', 50);

        if ($pageCount !== null && $pageCount > $maxPages) {
            throw new MediaAssetProcessingException(
                __('media_library.processing.pdf_page_limit', ['count' => $maxPages]),
                'pdf_page_limit_exceeded',
            );
        }

        $asset->update([
            'progress' => 25,
            'processing_stage' => 'storing_document',
        ]);

        $asset->clearMediaCollection('document');
        $asset->addMedia($sourcePath)
            ->preservingOriginal()
            ->usingName(pathinfo($asset->original_filename, PATHINFO_FILENAME))
            ->usingFileName(Str::uuid().'.pdf')
            ->toMediaCollection('document', config('media.asset_disk'));

        $asset->update([
            'progress' => 55,
            'processing_stage' => 'preparing_preview',
        ]);

        $previewPath = $this->pdfPreviewRenderer->renderFirstPage($sourcePath);

        try {
            $asset->clearMediaCollection('master');

            if ($previewPath !== null) {
                [$width, $height] = $this->dimensions($previewPath);
                $asset->update(['width' => $width, 'height' => $height]);

                $asset->addMedia($previewPath)
                    ->usingName(pathinfo($asset->original_filename, PATHINFO_FILENAME))
                    ->usingFileName(Str::uuid().'.webp')
                    ->toMediaCollection('master', config('media.asset_disk'));
                $previewPath = null;

                $this->assertConversionsGenerated($asset);
            }
        } finally {
            if ($previewPath !== null) {
                @unlink($previewPath);
            }
        }

        Storage::disk($asset->pending_disk)->delete($asset->pending_path);

        $asset->update([
            'status' => MediaAssetStatus::Ready,
            'pending_disk' => null,
            'pending_path' => null,
            'progress' => 100,
            'processing_stage' => null,
            'failure_code' => null,
            'failure_reason' => null,
        ]);
    }

    private function copyPendingFileToLocalPath(MediaAsset $asset): string
    {
        if (blank($asset->pending_disk) || blank($asset->pending_path)) {
            throw new MediaAssetProcessingException(
                'The original upload is no longer available. Remove this image and upload it again.',
                'source_missing',
            );
        }

        $stream = Storage::disk($asset->pending_disk)->readStream($asset->pending_path);

        if (! is_resource($stream)) {
            throw new MediaAssetProcessingException(
                'The original upload could not be read. Please retry the image.',
                'source_unreadable',
            );
        }

        $localPath = tempnam(sys_get_temp_dir(), 'soapkraft-media-');
        $localStream = $localPath === false ? false : fopen($localPath, 'wb');

        if ($localPath === false || ! is_resource($localStream)) {
            if (is_resource($localStream)) {
                fclose($localStream);
            }

            if (is_string($localPath)) {
                @unlink($localPath);
            }

            fclose($stream);

            throw new MediaAssetProcessingException(
                'The image processor is temporarily unavailable. Please retry the image.',
                'temporary_storage_unavailable',
            );
        }

        stream_copy_to_stream($stream, $localStream);
        fclose($stream);
        fclose($localStream);

        return $localPath;
    }

    private function assertCodecAvailable(MediaAsset $asset): void
    {
        $extension = strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION));

        if (! in_array($extension, ['heic', 'heif'], true)) {
            return;
        }

        if (! extension_loaded('imagick') || ! class_exists(Imagick::class)) {
            throw new MediaAssetProcessingException(
                'HEIC and HEIF images are not supported by this server. Convert the image to JPEG, PNG, or WebP and upload it again.',
                'codec_unavailable',
            );
        }

        $formats = array_map('strtoupper', Imagick::queryFormats());

        if (! in_array(strtoupper($extension), $formats, true) && ! in_array('HEIC', $formats, true)) {
            throw new MediaAssetProcessingException(
                'HEIC and HEIF images are not supported by this server. Convert the image to JPEG, PNG, or WebP and upload it again.',
                'codec_unavailable',
            );
        }
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $path): array
    {
        try {
            if (extension_loaded('imagick') && class_exists(Imagick::class)) {
                $image = new Imagick;
                $image->pingImage($path);
                $dimensions = [$image->getImageWidth(), $image->getImageHeight()];
                $image->clear();
            } else {
                $imageSize = getimagesize($path);
                $dimensions = $imageSize === false ? [0, 0] : [$imageSize[0], $imageSize[1]];
            }

            if ($dimensions[0] < 1 || $dimensions[1] < 1) {
                throw new MediaAssetProcessingException(
                    'The uploaded file is not a readable image.',
                    'invalid_image',
                );
            }

            return $dimensions;
        } catch (MediaAssetProcessingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaAssetProcessingException(
                'The uploaded file is not a readable image.',
                'invalid_image',
            );
        }
    }

    private function assertPixelLimit(int $width, int $height): void
    {
        $maxPixels = (int) config('media.asset_uploads.max_pixels', 25_000_000);

        if (($width * $height) > $maxPixels) {
            $maxMegapixels = (int) ceil($maxPixels / 1_000_000);

            throw new MediaAssetProcessingException(
                "The image dimensions are too large. Choose an image smaller than {$maxMegapixels} megapixels.",
                'pixel_limit_exceeded',
            );
        }
    }

    private function createMaster(string $sourcePath, MediaAsset $asset): string
    {
        try {
            $maxEdge = (int) config('media.asset_uploads.master_max_edge', 680);
            $masterPath = tempnam(sys_get_temp_dir(), 'soapkraft-master-');

            if ($masterPath === false) {
                throw new MediaAssetProcessingException(
                    'The image master could not be created. Please retry the image.',
                    'master_write_failed',
                );
            }

            if ($this->isAlreadyCompliantWebp($sourcePath, $asset, $maxEdge)) {
                if (! copy($sourcePath, $masterPath)) {
                    throw new MediaAssetProcessingException(
                        'The image master could not be created. Please retry the image.',
                        'master_write_failed',
                    );
                }

                return $masterPath;
            }

            $extension = strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION));
            $image = in_array($extension, ['heic', 'heif'], true)
                ? Image::useImageDriver(ImageDriverEnum::Imagick)->loadFile($sourcePath)
                : Image::load($sourcePath);

            $image
                ->orientation()
                ->fit(Fit::Max, $maxEdge, $maxEdge)
                ->format('webp')
                ->quality((int) config('media.asset_uploads.quality', 85))
                ->save($masterPath);

            return $masterPath;
        } catch (MediaAssetProcessingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaAssetProcessingException(
                'The uploaded image could not be decoded. Try converting it to JPEG, PNG, or WebP.',
                'decode_failed',
            );
        }
    }

    private function isAlreadyCompliantWebp(string $path, MediaAsset $asset, int $maxEdge): bool
    {
        if (strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION)) !== 'webp') {
            return false;
        }

        $details = $this->webpContainerDetails($path);

        if ($details === null) {
            return false;
        }

        return max($details['width'], $details['height']) <= $maxEdge
            && ! $details['has_orientation_or_metadata'];
    }

    /**
     * @return array{width: int, height: int, has_orientation_or_metadata: bool}|null
     */
    private function webpContainerDetails(string $path): ?array
    {
        $stream = @fopen($path, 'rb');

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $stat = fstat($stream);
            $fileSize = $stat['size'] ?? 0;
            $header = $this->readStreamBytes($stream, 12);

            if ($fileSize < 12 || $header === null || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
                return null;
            }

            $riffSize = unpack('Vsize', substr($header, 4, 4))['size'];
            $declaredEnd = $riffSize + 8;

            if ($declaredEnd !== $fileSize) {
                return null;
            }

            $width = null;
            $height = null;
            $hasOrientationOrMetadata = false;
            $seenDimensionChunks = [];
            $seenImageData = false;

            while (ftell($stream) < $declaredEnd) {
                $chunkHeader = $this->readStreamBytes($stream, 8);

                if ($chunkHeader === null) {
                    return null;
                }

                $type = substr($chunkHeader, 0, 4);
                $chunkLength = unpack('Vsize', substr($chunkHeader, 4, 4))['size'];
                $dataOffset = ftell($stream);
                $nextOffset = $dataOffset + $chunkLength + ($chunkLength % 2);

                if ($nextOffset > $declaredEnd) {
                    return null;
                }

                if (in_array($type, ['VP8X', 'VP8 ', 'VP8L'], true)) {
                    if (isset($seenDimensionChunks[$type])) {
                        return null;
                    }

                    $seenDimensionChunks[$type] = true;
                }

                if (in_array($type, ['VP8 ', 'VP8L'], true)) {
                    if ($seenImageData) {
                        return null;
                    }

                    $seenImageData = true;
                }

                if (in_array($type, ['EXIF', 'XMP ', 'ICCP'], true)) {
                    $hasOrientationOrMetadata = true;
                }

                $requiredBytes = match ($type) {
                    'VP8X', 'VP8 ' => 10,
                    'VP8L' => 5,
                    default => 0,
                };

                if ($chunkLength < $requiredBytes) {
                    return null;
                }

                $data = $requiredBytes > 0 ? $this->readStreamBytes($stream, $requiredBytes) : '';

                if ($data === null) {
                    return null;
                }

                $dimensions = match ($type) {
                    'VP8X' => [
                        1 + $this->webpLittleEndian24(substr($data, 4, 3)),
                        1 + $this->webpLittleEndian24(substr($data, 7, 3)),
                    ],
                    'VP8 ' => substr($data, 3, 3) === "\x9d\x01\x2a" ? [
                        unpack('vwidth', substr($data, 6, 2))['width'] & 0x3FFF,
                        unpack('vheight', substr($data, 8, 2))['height'] & 0x3FFF,
                    ] : null,
                    'VP8L' => ord($data[0]) === 0x2F ? $this->webpLosslessDimensions($data) : null,
                    default => null,
                };

                if (in_array($type, ['VP8X', 'VP8 ', 'VP8L'], true) && $dimensions === null) {
                    return null;
                }

                if ($dimensions !== null) {
                    if ($width !== null && ($width !== $dimensions[0] || $height !== $dimensions[1])) {
                        return null;
                    }

                    [$width, $height] = $dimensions;
                }

                if (fseek($stream, $nextOffset) !== 0) {
                    return null;
                }
            }

            if ($width === null || $height === null || $width < 1 || $height < 1 || ! $seenImageData) {
                return null;
            }

            return [
                'width' => $width,
                'height' => $height,
                'has_orientation_or_metadata' => $hasOrientationOrMetadata,
            ];
        } finally {
            fclose($stream);
        }
    }

    private function webpLittleEndian24(string $value): int
    {
        return ord($value[0]) | (ord($value[1]) << 8) | (ord($value[2]) << 16);
    }

    /**
     * @return array{int, int}
     */
    private function webpLosslessDimensions(string $data): array
    {
        $bits = ord($data[1]) | (ord($data[2]) << 8) | (ord($data[3]) << 16) | (ord($data[4]) << 24);

        return [
            1 + ($bits & 0x3FFF),
            1 + (($bits >> 14) & 0x3FFF),
        ];
    }

    /**
     * @param  resource  $stream
     */
    private function readStreamBytes($stream, int $length): ?string
    {
        $contents = fread($stream, $length);

        return is_string($contents) && strlen($contents) === $length ? $contents : null;
    }

    private function assertConversionsGenerated(MediaAsset $asset): void
    {
        $media = $asset->getFirstMedia('master');
        $missing = collect(['recipe-index', 'catalog', 'thumbnail', 'icon'])
            ->reject(fn (string $conversion): bool => $media?->hasGeneratedConversion($conversion) === true);

        if ($missing->isNotEmpty()) {
            throw new MediaAssetProcessingException(
                'The image sizes could not be prepared. Please retry the image.',
                'conversion_failed',
            );
        }
    }
}
