<?php

namespace App\Models;

use App\Enums\PackedLotSuffix;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'next_planning_serial',
    'permanent_prefix',
    'permanent_suffix',
    'permanent_padding',
    'next_permanent_serial',
    'packed_lot_suffix',
])]
class ProductionRunNumberSetting extends Model
{
    protected $attributes = [
        'next_planning_serial' => 1,
        'permanent_prefix' => 'B-',
        'permanent_suffix' => '',
        'permanent_padding' => 5,
        'next_permanent_serial' => 1,
        'packed_lot_suffix' => 'letter',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'next_planning_serial' => 'integer',
            'permanent_padding' => 'integer',
            'next_permanent_serial' => 'integer',
            'packed_lot_suffix' => PackedLotSuffix::class,
        ];
    }
}
