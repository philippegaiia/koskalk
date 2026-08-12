<?php

namespace App\Services;

use App\Models\Plan;

class PlanLimitDefaultsService
{
    public function ensureFormulaItemsPerRecipe(Plan $plan): void
    {
        $default = $this->formulaItemsPerRecipeDefault($plan);

        if ($default === null) {
            return;
        }

        $plan->limits()->firstOrCreate(
            ['key' => 'formula_items_per_recipe'],
            ['value' => $default],
        );
    }

    public function formulaItemsPerRecipeDefault(Plan $plan): ?int
    {
        if ($plan->slug === 'free-beta') {
            return 30;
        }

        return $plan->isBillable() ? 50 : null;
    }
}
