<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'prefix', 'suffix', 'date_format', 'date_source', 'separator', 'include_material_code', 'padding', 'reset_period'])]
class IngredientLotNumberSetting extends Model
{
    protected $attributes = [
        'prefix' => 'SK',
        'suffix' => '',
        'date_format' => 'ymd',
        'date_source' => 'created',
        'separator' => '-',
        'include_material_code' => false,
        'padding' => 1,
        'reset_period' => 'daily',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return ['include_material_code' => 'boolean', 'padding' => 'integer'];
    }
}
