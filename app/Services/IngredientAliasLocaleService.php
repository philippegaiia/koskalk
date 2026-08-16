<?php

namespace App\Services;

use App\Models\IngredientAlias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IngredientAliasLocaleService
{
    /**
     * @param  Collection<int, IngredientAlias>  $aliases
     * @param  array<int, string>  $activeLocales
     * @return Collection<int, IngredientAlias>
     */
    public function eligibleAliases(Collection $aliases, array $activeLocales): Collection
    {
        $activeAliases = $aliases->whereIn('locale', $activeLocales);
        $neutralAliases = $aliases->where('locale', 'und');
        $fallbackAliases = $activeAliases->isEmpty()
            ? $aliases->where('locale', 'en')
            : collect();

        return $activeAliases
            ->concat($neutralAliases)
            ->concat($fallbackAliases)
            ->unique('normalized_name')
            ->values();
    }

    /**
     * @param  array<int, string>  $activeLocales
     */
    public function applyEligibility(Builder $query, array $activeLocales): Builder
    {
        if ($activeLocales === []) {
            return $query->whereIn('locale', ['und', 'en']);
        }

        $aliasTable = (new IngredientAlias)->getTable();

        return $query->where(function (Builder $eligible) use ($activeLocales, $aliasTable): void {
            $eligible
                ->whereIn('locale', [...$activeLocales, 'und'])
                ->orWhere(function (Builder $englishFallback) use ($activeLocales, $aliasTable): void {
                    $englishFallback
                        ->where('locale', 'en')
                        ->whereNotExists(fn ($activeAlias) => $activeAlias
                            ->selectRaw('1')
                            ->from("{$aliasTable} as active_aliases")
                            ->whereColumn('active_aliases.ingredient_id', "{$aliasTable}.ingredient_id")
                            ->whereIn('active_aliases.locale', $activeLocales));
                });
        });
    }
}
