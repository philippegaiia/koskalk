<?php

namespace App\Services;

use App\Exceptions\MediaAssetProcessingException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PdfPreviewRenderer
{
    public function pageCount(string $pdfPath): ?int
    {
        $binary = $this->binary('pdfinfo_binary');

        if ($binary === null) {
            return null;
        }

        $process = new Process([$binary, $pdfPath]);
        $process->setTimeout($this->timeout());
        $process->run();

        if (! $process->isSuccessful() || preg_match('/^Pages:\\s+(\\d+)$/mi', $process->getOutput(), $matches) !== 1) {
            throw new MediaAssetProcessingException(
                __('media_library.processing.invalid_pdf'),
                'invalid_pdf',
            );
        }

        return (int) $matches[1];
    }

    public function renderFirstPage(string $pdfPath): ?string
    {
        $binary = $this->binary('pdftoppm_binary');

        if ($binary === null) {
            return null;
        }

        $outputPrefix = tempnam(sys_get_temp_dir(), 'soapkraft-pdf-page-');

        if ($outputPrefix === false) {
            throw new MediaAssetProcessingException(
                __('media_library.processing.preview_failed'),
                'pdf_preview_failed',
            );
        }

        @unlink($outputPrefix);
        $pngPath = $outputPrefix.'.png';
        $webpPath = tempnam(sys_get_temp_dir(), 'soapkraft-pdf-preview-');

        if ($webpPath === false) {
            throw new MediaAssetProcessingException(
                __('media_library.processing.preview_failed'),
                'pdf_preview_failed',
            );
        }

        try {
            $process = new Process([
                $binary,
                '-f',
                '1',
                '-singlefile',
                '-png',
                '-r',
                '120',
                $pdfPath,
                $outputPrefix,
            ]);
            $process->setTimeout($this->timeout());
            $process->run();

            if (! $process->isSuccessful() || ! is_file($pngPath)) {
                throw new MediaAssetProcessingException(
                    __('media_library.processing.preview_failed'),
                    'pdf_preview_failed',
                );
            }

            Image::load($pngPath)
                ->fit(
                    Fit::Max,
                    (int) config('media.asset_uploads.master_max_edge', 800),
                    (int) config('media.asset_uploads.master_max_edge', 800),
                )
                ->format('webp')
                ->quality((int) config('media.asset_uploads.quality', 85))
                ->save($webpPath);

            return $webpPath;
        } catch (MediaAssetProcessingException $exception) {
            @unlink($webpPath);

            throw $exception;
        } catch (Throwable) {
            @unlink($webpPath);

            throw new MediaAssetProcessingException(
                __('media_library.processing.preview_failed'),
                'pdf_preview_failed',
            );
        } finally {
            @unlink($pngPath);
        }
    }

    private function binary(string $key): ?string
    {
        $configured = (string) config("media.asset_uploads.pdf.{$key}");

        return (new ExecutableFinder)->find($configured);
    }

    private function timeout(): float
    {
        return max(1, (float) config('media.asset_uploads.pdf.process_timeout', 30));
    }
}
