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

    /**
     * Stored when a formula's label is not checked against any market.
     */
    public const NONE_CODE = 'none';

    /**
     * Resolve a submitted market code. Blank, unknown and inactive codes fail
     * closed to "none"; EU is checked like any other market, never assumed.
     */
    public static function normalizeCode(?string $value): string
    {
        $code = strtolower(trim((string) $value));

        if ($code === '' || $code === self::NONE_CODE) {
            return self::NONE_CODE;
        }

        $regimeExists = self::query()
            ->where('code', $code)
            ->whereIn('status', ['active', 'preview'])
            ->exists();

        return $regimeExists ? $code : self::NONE_CODE;
    }

    /**
     * Market choices offered for new formulas: none first, then every
     * active or preview market in display order.
     *
     * @return array<string, string> code => name
     */
    public static function selectableMarkets(): array
    {
        return [
            self::NONE_CODE => __('workbench.output.market_check.none_option'),
            ...self::query()
                ->whereIn('status', ['active', 'preview'])
                ->orderByDesc('is_default')
                ->orderBy('market_code')
                ->orderBy('name')
                ->pluck('name', 'code')
                ->all(),
        ];
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
