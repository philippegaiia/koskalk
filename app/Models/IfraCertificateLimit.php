<?php

namespace App\Models;

use Database\Factories\IfraCertificateLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ifra_certificate_id',
    'ifra_product_category_id',
    'max_percentage',
    'restriction_note',
    'source_data',
])]
class IfraCertificateLimit extends Model
{
    /** @use HasFactory<IfraCertificateLimitFactory> */
    use HasFactory;

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(IfraCertificate::class, 'ifra_certificate_id');
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    protected function casts(): array
    {
        return [
            'max_percentage' => 'decimal:5',
            'source_data' => 'array',
        ];
    }
}
