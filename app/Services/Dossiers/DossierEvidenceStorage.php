<?php

namespace App\Services\Dossiers;

use App\Models\DossierEvidenceFile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

class DossierEvidenceStorage
{
    public function assertAcceptable(UploadedFile $file): void
    {
        $maxKb = $this->isPdf($file) ? config('dossiers.evidence_pdf_max_kb') : config('dossiers.evidence_image_upload_max_kb');

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.implode(',', config('dossiers.evidence_mimes'))],
        ])->validate();
    }

    /**
     * Writes the file to the evidence disk under a random name and records its SHA-256. PDFs are stored as received;
     * images are compressed to WebP first, and the received file's fingerprint and size are recorded alongside.
     */
    public function store(Workspace $workspace, UploadedFile $file, ?User $uploader): DossierEvidenceFile
    {
        $disk = (string) config('dossiers.evidence_disk');
        $received = (string) file_get_contents($file->getRealPath());
        $compress = ! $this->isPdf($file);
        $contents = $compress ? $this->compressedWebp($file) : $received;
        $extension = $compress ? 'webp' : Str::lower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = config('dossiers.evidence_directory').'/'.$workspace->id.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('The evidence file could not be stored.');
        }

        return DossierEvidenceFile::query()->create([
            'workspace_id' => $workspace->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $compress ? 'image/webp' : (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_processing' => $compress ? 'compressed_webp' : 'as_received',
            'original_sha256' => hash('sha256', $received),
            'original_size_bytes' => strlen($received),
            'uploaded_by_user_id' => $uploader?->id,
        ]);
    }

    private function isPdf(UploadedFile $file): bool
    {
        return Str::lower((string) $file->guessExtension()) === 'pdf'
            || Str::lower($file->getClientOriginalExtension()) === 'pdf';
    }

    /**
     * Same recipe as the media pipeline: honour EXIF orientation, fit within the max edge, encode WebP.
     */
    private function compressedWebp(UploadedFile $file): string
    {
        $placeholder = (string) tempnam(sys_get_temp_dir(), 'dip-evidence-');
        $target = $placeholder.'.webp';
        $edge = (int) config('dossiers.evidence_image_max_edge');

        try {
            Image::load($file->getRealPath())
                ->orientation()
                ->fit(Fit::Max, $edge, $edge)
                ->format('webp')
                ->quality((int) config('dossiers.evidence_image_quality'))
                ->save($target);

            return (string) file_get_contents($target);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('dossiers.evidence.image_unreadable')]);
        } finally {
            @unlink($target);
            @unlink($placeholder);
        }
    }
}
