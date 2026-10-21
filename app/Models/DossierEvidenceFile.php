<?php

namespace App\Models;

use Database\Factories\DossierEvidenceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A stored evidence file. Immutable and never deleted by the application (10-year retention).
 */
#[Fillable(['workspace_id', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by_user_id'])]
class DossierEvidenceFile extends Model
{
    /** @use HasFactory<DossierEvidenceFileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence files are immutable.'));
        static::deleting(fn () => throw new LogicException('Evidence files are retained and cannot be deleted.'));
    }
}
