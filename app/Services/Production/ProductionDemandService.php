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
        $key = $subject instanceof Ingredient
            ? 'ingredient:'.$subject->id
            : 'packaging:'.$subject->id;

        return $this->forWorkspaceSubjects($workspace, [$key])[$key] ?? '0.000000000';
    }

    /**
     * @param  list<string>  $subjectKeys  Keys in the form of "ingredient:{id}" or "packaging:{id}".
     * @return array<string, string> Demand per subject key, zero-filled for requested keys.
     */
    public function forWorkspaceSubjects(Workspace $workspace, array $subjectKeys): array
    {
        if ($subjectKeys === []) {
            return [];
        }

        $ingredientIds = [];
        $packagingItemIds = [];

        foreach ($subjectKeys as $key) {
            if (str_starts_with($key, 'ingredient:')) {
                $ingredientIds[] = (int) substr($key, strlen('ingredient:'));
            } elseif (str_starts_with($key, 'packaging:')) {
                $packagingItemIds[] = (int) substr($key, strlen('packaging:'));
            }
        }

        $demands = array_fill_keys($subjectKeys, '0.000000000');

        if ($ingredientIds === [] && $packagingItemIds === []) {
            return $demands;
        }

        $requirements = ProductionRequirement::query()
            ->whereHas('productionRun', function (Builder $query) use ($workspace): void {
                $query
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('status', [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved]);
            })
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->where(function (Builder $query) use ($ingredientIds, $packagingItemIds): void {
                if ($ingredientIds !== []) {
                    $query->orWhereIn('ingredient_id', $ingredientIds);
                }

                if ($packagingItemIds !== []) {
                    $query->orWhereIn('packaging_item_id', $packagingItemIds);
                }
            })
            ->get(['ingredient_id', 'packaging_item_id', 'required_mass_grams', 'required_units', 'active_reserved_quantity']);

        foreach ($requirements as $requirement) {
            $isIngredient = $requirement->ingredient_id !== null;
            $key = $isIngredient
                ? 'ingredient:'.$requirement->ingredient_id
                : 'packaging:'.$requirement->packaging_item_id;

            if (! array_key_exists($key, $demands)) {
                continue;
            }

            $required = $isIngredient
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;
            $reserved = (string) ($requirement->active_reserved_quantity ?? '0');
            $remaining = bcsub($required, $reserved, 9);

            if (bccomp($remaining, '0', 9) > 0) {
                $demands[$key] = bcadd($demands[$key], $remaining, 9);
            }
        }

        return $demands;
    }
}
