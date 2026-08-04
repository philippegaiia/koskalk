<?php

namespace App\Services\Production;

use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\StockLotStatus;
use App\StockReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockReservationProposalService
{
    /**
     * @return array{production: ProductionRun, requirements: list<array<string, mixed>>}
     */
    public function forProduction(ProductionRun $production): array
    {
        $production->loadMissing(['workspace', 'requirements']);
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->assertProduction($production, $workspace);

        return [
            'production' => $production,
            'requirements' => $production->requirements
                ->map(fn (ProductionRequirement $requirement): array => $this->forRequirement($requirement))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  iterable<ProductionRun>  $productions
     * @return list<array{production: ProductionRun, requirements: list<array<string, mixed>>}>
     */
    public function forProductions(iterable $productions): array
    {
        return collect($productions)
            ->sort(function (ProductionRun $left, ProductionRun $right): int {
                $leftDate = $left->planned_for?->toDateString();
                $rightDate = $right->planned_for?->toDateString();

                if ($leftDate === null && $rightDate !== null) {
                    return 1;
                }

                if ($leftDate !== null && $rightDate === null) {
                    return -1;
                }

                return [$leftDate, $left->id] <=> [$rightDate, $right->id];
            })
            ->map(fn (ProductionRun $production): array => $this->forProduction($production))
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>|null  $manualLotIds
     * @return array{
     *     requirement: ProductionRequirement,
     *     required: string,
     *     already_reserved: string,
     *     remaining: string,
     *     proposed: string,
     *     missing: string,
     *     eligible_lots: list<array{lot: StockLot, available: string}>,
     *     allocations: list<array{lot: StockLot, quantity: string, available: string}>
     * }
     */
    public function forRequirement(ProductionRequirement $requirement, ?array $manualLotIds = null): array
    {
        $requirement->loadMissing('productionRun.workspace');
        $production = $requirement->productionRun;
        $workspace = $production?->workspace;

        if (! $production instanceof ProductionRun || ! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'requirement' => 'The production requirement is not connected to a workspace.',
            ]);
        }

        $this->assertProduction($production, $workspace);

        if (($requirement->ingredient_id === null) === ($requirement->packaging_item_id === null)) {
            throw ValidationException::withMessages([
                'requirement' => 'A production requirement must have exactly one material subject.',
            ]);
        }

        $isIngredient = $requirement->ingredient_id !== null;
        $required = $isIngredient
            ? (string) $requirement->required_mass_grams
            : (string) $requirement->required_units;
        $alreadyReserved = $this->activeReservationQuantity($requirement);
        $remaining = $this->positiveDifference($required, $alreadyReserved);
        $lots = $this->eligibleLots($workspace, $requirement, $production);

        if ($manualLotIds !== null && $manualLotIds !== []) {
            $lots = $this->manualLots($lots, $manualLotIds);
        }

        $eligibleLots = $lots
            ->map(fn (StockLot $lot): array => [
                'lot' => $lot,
                'available' => $this->availableQuantity($lot),
            ])
            ->filter(fn (array $row): bool => bccomp($row['available'], '0', 9) > 0)
            ->values();
        $allocations = [];
        $proposed = '0.000000000';

        foreach ($eligibleLots as $row) {
            if (bccomp($remaining, '0', 9) <= 0) {
                break;
            }

            $quantity = bccomp($row['available'], $remaining, 9) >= 0
                ? $remaining
                : $row['available'];
            $allocations[] = [
                'lot' => $row['lot'],
                'quantity' => $quantity,
                'available' => $row['available'],
            ];
            $proposed = bcadd($proposed, $quantity, 9);
            $remaining = bcsub($remaining, $quantity, 9);
        }

        return [
            'requirement' => $requirement,
            'required' => $required,
            'already_reserved' => $alreadyReserved,
            'remaining' => $this->positiveDifference($required, $alreadyReserved),
            'proposed' => $proposed,
            'missing' => $remaining,
            'eligible_lots' => $eligibleLots->all(),
            'allocations' => $allocations,
        ];
    }

    private function assertProduction(ProductionRun $production, Workspace $workspace): void
    {
        if ($production->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages([
                'production' => 'The production does not belong to this workspace.',
            ]);
        }

        if (! in_array($production->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
            throw ValidationException::withMessages([
                'production' => 'Only planned or stock-prepared productions can reserve stock.',
            ]);
        }
    }

    private function activeReservationQuantity(ProductionRequirement $requirement): string
    {
        $quantity = '0.000000000';

        foreach ($requirement->reservations()->where('status', StockReservationStatus::Active)->get(['quantity']) as $reservation) {
            $quantity = bcadd($quantity, (string) $reservation->quantity, 9);
        }

        return $quantity;
    }

    /**
     * @return Collection<int, StockLot>
     */
    private function eligibleLots(Workspace $workspace, ProductionRequirement $requirement, ProductionRun $production): Collection
    {
        $plannedFor = $production->planned_for?->toDateString();
        $isIngredient = $requirement->ingredient_id !== null;

        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', StockLotStatus::Released)
            ->when(
                $isIngredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $requirement->ingredient_id)->whereNull('packaging_item_id'),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $requirement->packaging_item_id)->whereNull('ingredient_id'),
            )
            ->when($plannedFor !== null, function (Builder $query) use ($plannedFor): void {
                $query
                    ->where(fn (Builder $nested): Builder => $nested->whereNull('available_from')->orWhereDate('available_from', '<=', $plannedFor))
                    ->where(fn (Builder $nested): Builder => $nested->whereNull('expires_at')->orWhereDate('expires_at', '>=', $plannedFor));
            })
            ->withSum('movements', 'quantity_delta')
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->get();

        return $lots->sort(function (StockLot $left, StockLot $right): int {
            $leftExpiry = $left->expires_at?->toDateString();
            $rightExpiry = $right->expires_at?->toDateString();

            if ($leftExpiry === null && $rightExpiry !== null) {
                return 1;
            }

            if ($leftExpiry !== null && $rightExpiry === null) {
                return -1;
            }

            return [$leftExpiry, $left->stocked_at?->toDateString(), $left->internal_lot_code]
                <=> [$rightExpiry, $right->stocked_at?->toDateString(), $right->internal_lot_code];
        });
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     * @param  list<int|string>  $manualLotIds
     * @return Collection<int, StockLot>
     */
    private function manualLots(Collection $lots, array $manualLotIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $manualLotIds)));
        $byId = $lots->keyBy('id');

        foreach ($ids as $id) {
            if (! $byId->has($id)) {
                throw ValidationException::withMessages([
                    'lot' => 'One or more selected lots are not eligible for this requirement.',
                ]);
            }
        }

        return collect($ids)->map(fn (int $id): StockLot => $byId->get($id))->values();
    }

    private function availableQuantity(StockLot $lot): string
    {
        $physical = bcadd((string) ($lot->movements_sum_quantity_delta ?? '0'), '0', 9);
        $reserved = bcadd((string) ($lot->active_reserved_quantity ?? '0'), '0', 9);

        return $this->positiveDifference($physical, $reserved);
    }

    private function positiveDifference(string $left, string $right): string
    {
        $difference = bcsub($left, $right, 9);

        return bccomp($difference, '0', 9) > 0 ? $difference : '0.000000000';
    }
}
