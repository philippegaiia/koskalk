<?php

namespace App\Models;

use App\Enums\FileExportType;
use App\Models\Concerns\HasPublicId;
use Database\Factories\FileExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'workspace_id', 'type', 'status', 'parameters', 'locale', 'disk', 'path', 'filename', 'error', 'expires_at'])]
class FileExport extends Model
{
    /** @use HasFactory<FileExportFactory> */
    use HasFactory;

    use HasPublicId;
    use Prunable;

    protected function casts(): array
    {
        return [
            'type' => FileExportType::class,
            'parameters' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    protected function pruning(): void
    {
        Storage::disk($this->disk)->deleteDirectory('exports/'.$this->public_id);
        Storage::disk('local')->deleteDirectory('export-tmp/'.$this->public_id);
    }
}
