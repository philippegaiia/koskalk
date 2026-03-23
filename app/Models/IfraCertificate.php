<?php

namespace App\Models;

use Database\Factories\IfraCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ingredient_version_id',
    'certificate_name',
    'document_name',
    'document_path',
    'issuer',
    'reference_code',
    'ifra_amendment',
    'published_at',
    'valid_from',
    'is_current',
    'source_notes',
    'source_data',
])]
class IfraCertificate extends Model
{
    /** @use HasFactory<IfraCertificateFactory> */
    use HasFactory;

    public function ingredientVersion(): BelongsTo
    {
        return $this->belongsTo(IngredientVersion::class);
    }

    public function limits(): HasMany
    {
        return $this->hasMany(IfraCertificateLimit::class);
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'valid_from' => 'date',
            'is_current' => 'bool',
            'source_data' => 'array',
        ];
    }
}
