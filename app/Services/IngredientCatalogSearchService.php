<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Builder;

final class IngredientCatalogSearchService
{
    /**
     * @param  array<int, string>|null  $translationLocales
     */
    public function apply(Builder $query, string $term, ?array $translationLocales = null): Builder
    {
        $search = mb_strtolower(trim($term));

        if ($search === '') {
            return $query;
        }

        $translationLocales ??= Ingredient::translationLocaleCandidates();
        $like = '%'.$search.'%';
        $locales = array_values(array_unique([
            ...$translationLocales,
            'und',
            'en',
        ]));

        return $query->where(function (Builder $where) use ($like, $locales): void {
            $where
                ->whereRaw('LOWER(display_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inci_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(category) LIKE ?', [$like])
                ->orWhereHas('translations', fn (Builder $translation): Builder => $translation
                    ->whereIn('locale', $locales)
                    ->whereRaw('LOWER(display_name) LIKE ?', [$like]))
                ->orWhereHas('aliases', fn (Builder $alias): Builder => $alias
                    ->whereIn('locale', $locales)
                    ->whereRaw('LOWER(normalized_name) LIKE ?', [$like]))
                ->orWhereHas('identifiers', fn (Builder $identifier): Builder => $identifier
                    ->whereRaw('LOWER(normalized_value) LIKE ?', [$like]));
        });
    }
}
