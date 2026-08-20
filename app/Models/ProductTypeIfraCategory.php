<?php

namespace App\Models;

use Database\Factories\ProductTypeIfraCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_type_id',
    'ifra_amendment_id',
    'ifra_product_category_id',
    'is_default',
    'guidance',
    'source_url',
    'sort_order',
    'is_active',
])]
class ProductTypeIfraCategory extends Model
{
    /** @use HasFactory<ProductTypeIfraCategoryFactory> */
    use HasFactory;

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function ifraAmendment(): BelongsTo
    {
        return $this->belongsTo(IfraAmendment::class);
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'bool',
            'sort_order' => 'integer',
            'is_active' => 'bool',
        ];
    }
}
