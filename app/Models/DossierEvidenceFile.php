<?php

namespace App\Models;

use Database\Factories\DossierEvidenceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A stored evidence file. Immutable and never deleted by the application (10-year retention).
 * PDFs are kept as received; images are compressed to WebP on upload, with the original's fingerprint and size
 * recorded so the received file can still be identified.
 */
#[Fillable([
    'workspace_id', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'sha256',
    'storage_processing', 'original_sha256', 'original_size_bytes', 'uploaded_by_user_id',
])]
class DossierEvidenceFile extends Model
{
    /** @use HasFactory<DossierEvidenceFileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence files are immutable.'));
        static::deleting(fn () => throw new LogicException('Evidence files are retained and cannot be deleted.'));
    }

    /**
     * The name to download under: the original name, with the stored format's extension when it was converted.
     */
    public function downloadFilename(): string
    {
        if ($this->storage_processing !== 'compressed_webp') {
            return $this->original_filename;
        }

        return pathinfo($this->original_filename, PATHINFO_FILENAME).'.webp';
    }
}
