<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'code',
    'name',
    'contact_name',
    'email',
    'phone',
    'address_line_1',
    'address_line_2',
    'city',
    'region',
    'postal_code',
    'country_code',
    'website',
    'default_currency',
    'notes',
    'is_active',
])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function listings(): HasMany
    {
        return $this->hasMany(SupplierListing::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
