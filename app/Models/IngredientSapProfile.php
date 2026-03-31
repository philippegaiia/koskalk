<?php

namespace App\Models;

use App\SoapSap;
use Database\Factories\IngredientSapProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'koh_sap_value',
    'iodine_value',
    'ins_value',
    'source_notes',
])]
class IngredientSapProfile extends Model
{
    /** @use HasFactory<IngredientSapProfileFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'koh_sap_value' => 'decimal:6',
            'iodine_value' => 'decimal:3',
            'ins_value' => 'decimal:3',
        ];
    }

    protected function naohSapValue(): Attribute
    {
        return Attribute::make(
            get: fn (): ?float => $this->koh_sap_value === null
                ? null
                : round(SoapSap::deriveNaohFromKoh((float) $this->koh_sap_value), 6),
        );
    }

    protected function kohSapValue(): Attribute
    {
        return Attribute::make(
            set: fn (float|int|string|null $value): ?float => $value === null || $value === ''
                ? null
                : SoapSap::normalizeKohSapInput((float) $value),
        );
    }
}
