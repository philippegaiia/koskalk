<?php

namespace App\Services\Dossiers;

use App\Models\DossierEvidenceFile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class DossierEvidenceStorage
{
    public function assertAcceptable(UploadedFile $file): void
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.config('dossiers.evidence_max_kb'), 'mimes:'.implode(',', config('dossiers.evidence_mimes'))],
        ])->validate();
    }

    /**
     * Writes the file to the private evidence disk under a random name and records its SHA-256.
     */
    public function store(Workspace $workspace, UploadedFile $file, ?User $uploader): DossierEvidenceFile
    {
        $disk = (string) config('dossiers.evidence_disk');
        $contents = (string) file_get_contents($file->getRealPath());
        $extension = Str::lower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = config('dossiers.evidence_directory').'/'.$workspace->id.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('The evidence file could not be stored.');
        }

        return DossierEvidenceFile::query()->create([
            'workspace_id' => $workspace->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'uploaded_by_user_id' => $uploader?->id,
        ]);
    }
}
