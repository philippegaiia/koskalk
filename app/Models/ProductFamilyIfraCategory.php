<?php

namespace App\Models;

use Database\Factories\ProductFamilyIfraCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_family_id',
    'ifra_product_category_id',
    'is_default',
    'sort_order',
])]
class ProductFamilyIfraCategory extends Model
{
    /** @use HasFactory<ProductFamilyIfraCategoryFactory> */
    use HasFactory;

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'bool',
        ];
    }
}
