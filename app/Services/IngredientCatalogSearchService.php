<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Builder;

final class IngredientCatalogSearchService
{
    public function __construct(
        private readonly IngredientAliasLocaleService $ingredientAliasLocaleService,
    ) {}

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

        return $query->where(function (Builder $where) use ($like, $translationLocales): void {
            $where
                ->whereRaw('LOWER(display_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inci_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(category) LIKE ?', [$like])
                ->orWhereHas('translations', fn (Builder $translation): Builder => $translation
                    ->whereIn('locale', $translationLocales)
                    ->whereRaw('LOWER(display_name) LIKE ?', [$like]))
                ->orWhereHas('aliases', fn (Builder $alias): Builder => $this->ingredientAliasLocaleService
                    ->applyEligibility($alias, $translationLocales)
                    ->whereRaw('LOWER(normalized_name) LIKE ?', [$like]))
                ->orWhereHas('identifiers', fn (Builder $identifier): Builder => $identifier
                    ->whereRaw('LOWER(normalized_value) LIKE ?', [$like]));
        });
    }
}
