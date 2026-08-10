<?php

namespace App\Models;

use Database\Factories\ProductionOutputSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'soap_ready_delay_days',
    'cosmetic_ready_delay_days',
])]
class ProductionOutputSetting extends Model
{
    /** @use HasFactory<ProductionOutputSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'soap_ready_delay_days' => 21,
        'cosmetic_ready_delay_days' => 3,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'soap_ready_delay_days' => 'integer',
            'cosmetic_ready_delay_days' => 'integer',
        ];
    }
}
