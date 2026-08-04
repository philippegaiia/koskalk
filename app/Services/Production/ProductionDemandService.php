<?php

namespace App\Services\Production;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\Workspace;
use App\ProductionRunStatus;
use Illuminate\Database\Eloquent\Builder;

class ProductionDemandService
{
    public function forWorkspaceSubject(Workspace $workspace, Ingredient|PackagingItem $subject): string
    {
        $requirements = ProductionRequirement::query()
            ->whereHas('productionRun', function (Builder $query) use ($workspace): void {
                $query
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('status', [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved]);
            })
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->get(['required_mass_grams', 'required_units']);

        $demand = '0.000000000';

        foreach ($requirements as $requirement) {
            $quantity = $subject instanceof Ingredient
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;
            $demand = bcadd($demand, $quantity, 9);
        }

        return $demand;
    }
}
