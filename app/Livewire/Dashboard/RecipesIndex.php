<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecipesIndex extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public function render(): View
    {
        $catalogStatsRow = Ingredient::query()
            ->selectRaw(
                '
                COALESCE(SUM(CASE WHEN category = ? AND is_potentially_saponifiable = ? THEN 1 ELSE 0 END), 0) as carrier_oils,
                COALESCE(SUM(CASE WHEN category IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as aromatics,
                COALESCE(SUM(CASE WHEN category IN (?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END), 0) as additives
                ',
                [
                    IngredientCategory::CarrierOil->value,
                    true,
                    ...IngredientCategory::aromaticValues(),
                    IngredientCategory::BotanicalExtract->value,
                    IngredientCategory::Clay->value,
                    IngredientCategory::Glycol->value,
                    IngredientCategory::Additive->value,
                    IngredientCategory::Colorant->value,
                    IngredientCategory::Preservative->value,
                ],
            )
            ->first();

        $catalogStats = [
            'carrier_oils' => (int) ($catalogStatsRow?->carrier_oils ?? 0),
            'aromatics' => (int) ($catalogStatsRow?->aromatics ?? 0),
            'additives' => (int) ($catalogStatsRow?->additives ?? 0),
        ];

        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $draftCount = 0;
        $savedFormulaCount = 0;
        $searchTerm = trim($this->search);

        if ($currentUser !== null) {
            $recipesQuery = Recipe::query()
                ->with([
                    'productFamily',
                    'currentDraftVersion',
                    'currentSavedVersion',
                ])
                ->whereNull('archived_at');

            if ($searchTerm !== '') {
                $recipesQuery->where(function (Builder $query) use ($searchTerm): void {
                    $query
                        ->where('name', 'ilike', '%'.$searchTerm.'%')
                        ->orWhereHas('productFamily', fn (Builder $familyQuery) => $familyQuery->where('name', 'ilike', '%'.$searchTerm.'%'));
                });
            }

            $recipes = $recipesQuery
                ->latest()
                ->get();

            $recipeCount = $recipes->count();
            $draftCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentDraftVersion !== null)->count();
            $savedFormulaCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentSavedVersion !== null)->count();
        }

        return view('livewire.dashboard.recipes-index', [
            'currentUser' => $currentUser,
            'catalogStats' => $catalogStats,
            'recipeCount' => $recipeCount,
            'draftCount' => $draftCount,
            'savedFormulaCount' => $savedFormulaCount,
            'recipes' => $recipes,
            'searchTerm' => $searchTerm,
        ]);
    }
}
