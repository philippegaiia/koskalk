<?php

namespace App\Models;

use App\SoapFattyAcid;
use App\SoapSap;
use Database\Factories\IngredientSapProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_version_id',
    'koh_sap_value',
    'lauric',
    'myristic',
    'palmitic',
    'stearic',
    'ricinoleic',
    'oleic',
    'linoleic',
    'linolenic',
    'source_notes',
])]
class IngredientSapProfile extends Model
{
    /** @use HasFactory<IngredientSapProfileFactory> */
    use HasFactory;

    public function ingredientVersion(): BelongsTo
    {
        return $this->belongsTo(IngredientVersion::class);
    }

    /**
     * @return array<string, float>
     */
    public function fattyAcidProfile(): array
    {
        return collect(SoapFattyAcid::coreSet())
            ->mapWithKeys(function (SoapFattyAcid $fattyAcid): array {
                $value = $this->getAttributeValue($fattyAcid->value);

                return $value === null
                    ? []
                    : [$fattyAcid->value => round((float) $value, 2)];
            })
            ->all();
    }

    public function hasFattyAcidProfile(): bool
    {
        return $this->fattyAcidProfile() !== [];
    }

    protected function casts(): array
    {
        return array_merge([
            'koh_sap_value' => 'decimal:6',
        ], collect(SoapFattyAcid::coreSet())
            ->mapWithKeys(fn (SoapFattyAcid $fattyAcid): array => [$fattyAcid->value => 'decimal:2'])
            ->all());
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
