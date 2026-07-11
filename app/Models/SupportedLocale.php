<?php

namespace App\Models;

use Database\Factories\SupportedLocaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'native_name',
    'number_locale',
    'text_direction',
    'is_active',
    'is_default',
    'sort_order',
])]
class SupportedLocale extends Model
{
    /** @use HasFactory<SupportedLocaleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $locale): void {
            if ($locale->is_default) {
                $locale->is_active = true;
            }
        });

        static::saved(function (self $locale): void {
            if ($locale->is_default) {
                self::query()
                    ->whereKeyNot($locale->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'is_default' => 'bool',
            'sort_order' => 'integer',
        ];
    }
}
