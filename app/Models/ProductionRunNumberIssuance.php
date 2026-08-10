<?php

namespace App\Models;

use Database\Factories\ProductionRunNumberIssuanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'production_run_id',
    'batch_number',
    'serial',
    'issued_by_user_id',
    'issued_at',
])]
class ProductionRunNumberIssuance extends Model
{
    /** @use HasFactory<ProductionRunNumberIssuanceFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'serial' => 'integer',
            'issued_at' => 'datetime',
        ];
    }
}
