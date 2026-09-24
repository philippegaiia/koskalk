<?php

namespace App\Models;

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\HelpContentExportFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'requires_admin_authorization',
    'public_id',
    'removed_at',
    'status',
    'processing_token',
    'disk',
    'path',
    'format_version',
    'checksum',
    'size_bytes',
    'requested_by',
    'reason',
    'captured_at',
    'started_at',
    'completed_at',
    'error_code',
    'error_message',
])]
class HelpContentExport extends Model
{
    /** @use HasFactory<HelpContentExportFactory> */
    use HasFactory;

    use HasPublicId;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new DomainException('Help audit records cannot be deleted.');
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'requires_admin_authorization' => 'boolean',
            'status' => HelpContentExportStatus::class,
            'reason' => HelpContentExportReason::class,
            'format_version' => 'integer',
            'size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }
}
