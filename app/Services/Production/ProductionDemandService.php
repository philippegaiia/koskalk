<?php

namespace App\Services\Production;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\StockReservationStatus;
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
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->get(['required_mass_grams', 'required_units']);

        $demand = '0.000000000';

        foreach ($requirements as $requirement) {
            $required = $subject instanceof Ingredient
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;
            $reserved = (string) ($requirement->active_reserved_quantity ?? '0');
            $remaining = bcsub($required, $reserved, 9);

            if (bccomp($remaining, '0', 9) > 0) {
                $demand = bcadd($demand, $remaining, 9);
            }
        }

        return $demand;
    }
}
