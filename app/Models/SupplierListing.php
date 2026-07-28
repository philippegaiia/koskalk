<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\StockUnitKind;
use Database\Factories\SupplierListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'supplier_id', 'ingredient_id', 'user_packaging_item_id', 'supplier_sku',
    'supplier_name', 'pack_description', 'container', 'unit_kind', 'canonical_quantity_per_pack',
    'commercial_quantity', 'commercial_unit', 'pack_price', 'currency', 'minimum_packs', 'notes', 'is_active',
])]
class SupplierListing extends Model
{
    /** @use HasFactory<SupplierListingFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'unit_kind' => StockUnitKind::class,
            'canonical_quantity_per_pack' => 'decimal:9',
            'commercial_quantity' => 'decimal:9',
            'pack_price' => 'decimal:9',
            'minimum_packs' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
