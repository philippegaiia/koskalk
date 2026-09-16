<?php

namespace App\Models;

use Database\Factories\ProductionFlashSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'idempotency_hash', 'request_hash', 'production_ids', 'uses_production_locations'])]
class ProductionFlashSubmission extends Model
{
    /** @use HasFactory<ProductionFlashSubmissionFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return ['production_ids' => 'array', 'uses_production_locations' => 'boolean'];
    }
}
