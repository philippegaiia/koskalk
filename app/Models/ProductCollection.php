<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'brand_id', 'name', 'slug'])]
class ProductCollection extends Model
{
    /** @use HasFactory<ProductCollectionFactory> */
    use HasFactory;

    use HasPublicId;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }
}
