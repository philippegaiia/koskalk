<?php

namespace App\Models;

use Database\Factories\RegulatoryRegimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'market_code',
    'name',
    'version_label',
    'status',
    'is_default',
    'effective_from',
    'effective_until',
    'source_name',
    'source_url',
    'reviewed_at',
    'notes',
    'source_data',
])]
class RegulatoryRegime extends Model
{
    /** @use HasFactory<RegulatoryRegimeFactory> */
    use HasFactory;

    public static function normalizeCode(?string $value): string
    {
        $code = strtolower(trim((string) $value));

        if ($code === '' || $code === 'eu') {
            return 'eu';
        }

        $regimeExists = self::query()
            ->where('code', $code)
            ->whereIn('status', ['active', 'preview'])
            ->exists();

        return $regimeExists ? $code : 'eu';
    }

    public function allergenRules(): HasMany
    {
        return $this->hasMany(RegulatoryRegimeAllergen::class);
    }

    public function substanceRules(): HasMany
    {
        return $this->hasMany(RegulatoryRegimeSubstanceRule::class);
    }

    public function recipeVersions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'bool',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'reviewed_at' => 'datetime',
            'source_data' => 'array',
        ];
    }
}
