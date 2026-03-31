<?php

namespace App\Livewire\Dashboard;

use App\Models\Ingredient;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class IngredientsIndex extends Component
{
    public function render(): View
    {
        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $ingredients = collect();

        if ($currentUser instanceof User) {
            $ingredients = Ingredient::query()
                ->ownedByUser($currentUser)
                ->withCount('components')
                ->latest()
                ->get();
        }

        return view('livewire.dashboard.ingredients-index', [
            'currentUser' => $currentUser,
            'ingredients' => $ingredients,
            'ingredientCount' => $ingredients->count(),
        ]);
    }
}
