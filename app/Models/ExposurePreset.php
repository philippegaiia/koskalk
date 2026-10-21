<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\ExposurePresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exposure assumptions for one finished-product type.
 */
#[Fillable([
    'product_type_id', 'version', 'is_current', 'superseded_at',
    'amount_per_use_g', 'uses_per_day', 'retention_factor', 'is_rinse_off',
    'application_sites', 'direct_routes', 'secondary_routes', 'target_population',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class ExposurePreset extends Model
{
    use HasAppendOnlyVersions;

    /** @use HasFactory<ExposurePresetFactory> */
    use HasFactory;

    public static function versionScopeColumns(): array
    {
        return ['product_type_id'];
    }

    public static function publicationRules(): array
    {
        return [
            'product_type_id' => ['required', 'integer', 'exists:product_types,id'],
            'amount_per_use_g' => ['required', 'numeric', 'gt:0'],
            'uses_per_day' => ['required', 'numeric', 'gt:0'],
            'retention_factor' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'is_rinse_off' => ['required', 'boolean'],
            'application_sites' => ['required', 'array', 'min:1'],
            'direct_routes' => ['required', 'array', 'min:1'],
            'secondary_routes' => ['present', 'array'],
            'target_population' => ['required', 'string', 'max:255'],
            'source_reference' => ['required', 'string'],
        ];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'amount_per_use_g' => 'decimal:4',
            'uses_per_day' => 'decimal:3',
            'retention_factor' => 'decimal:5',
            'is_rinse_off' => 'bool',
            'application_sites' => 'array',
            'direct_routes' => 'array',
            'secondary_routes' => 'array',
        ];
    }
}
