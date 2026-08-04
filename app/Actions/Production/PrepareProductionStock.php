<?php

namespace App\Actions\Production;

use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\Production\StockReservationProposalService;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareProductionStock
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly StockReservationProposalService $proposal,
    ) {}

    /**
     * @param  list<int|string>  $productionIds
     * @param  array<string, list<array{stock_lot_id: int|string, quantity: int|float|string}>>  $manualAllocations
     * @return list<ProductionRun>
     */
    public function handle(
        User $actor,
        array $productionIds,
        string $idempotencyKey,
        array $manualAllocations = [],
    ): array {
        $productionIds = $this->normalizeProductionIds($productionIds);
        $idempotencyKey = trim($idempotencyKey);

        if ($productionIds === []) {
            throw ValidationException::withMessages([
                'productions' => 'Select at least one planned production.',
            ]);
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages([
                'idempotencyKey' => 'A valid preparation key is required.',
            ]);
        }

        $productions = ProductionRun::query()
            ->whereIn('id', $productionIds)
            ->with('workspace')
            ->get();

        if ($productions->count() !== count($productionIds)) {
            throw ValidationException::withMessages([
                'productions' => 'One or more selected productions could not be found.',
            ]);
        }

        $workspaceIds = $productions->pluck('workspace_id')->unique()->values();

        if ($workspaceIds->count() !== 1) {
            throw ValidationException::withMessages([
                'productions' => 'Selected productions must belong to the same workspace.',
            ]);
        }

        $workspace = $productions->first()?->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production_bench' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $idempotencyKey, $manualAllocations, $productionIds, $workspace): array {
            $lockedProductions = ProductionRun::query()
                ->whereIn('id', $productionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedProductions->count() !== count($productionIds)) {
                throw ValidationException::withMessages([
                    'productions' => 'One or more selected productions could not be found.',
                ]);
            }

            if ($lockedProductions->contains(fn (ProductionRun $production): bool => (int) $production->workspace_id !== (int) $workspace->id)) {
                throw ValidationException::withMessages([
                    'productions' => 'Selected productions must belong to the same workspace.',
                ]);
            }

            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($workspace->id);

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production_bench' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            $this->assertProductionStatuses($lockedProductions);

            $requirements = ProductionRequirement::query()
                ->whereIn('production_run_id', $lockedProductions->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertManualRequirementKeys($manualAllocations, $requirements);

            $lots = $this->lockSubjectLots($lockedWorkspace, $requirements);
            $this->lockCompetingReservations($lockedProductions, $requirements, $lots);
            $allocations = $this->buildAllocations($requirements, $manualAllocations);

            foreach ($allocations as $allocation) {
                $this->createReservation(
                    workspace: $lockedWorkspace,
                    allocation: $allocation,
                    idempotencyKey: $idempotencyKey,
                    actor: $actor,
                );
            }

            $requirementsByProduction = $requirements->groupBy('production_run_id');

            foreach ($lockedProductions as $production) {
                if ($this->productionIsFullyReserved($requirementsByProduction->get($production->id, collect()))) {
                    $production->update(['status' => ProductionRunStatus::Reserved]);
                }
            }

            return $lockedProductions
                ->map(fn (ProductionRun $production): ProductionRun => $production->fresh(['requirements', 'recipe']))
                ->values()
                ->all();
        }, attempts: 5);
    }

    /**
     * @param  list<int|string>  $productionIds
     * @return list<int>
     */
    private function normalizeProductionIds(array $productionIds): array
    {
        $ids = [];

        foreach ($productionIds as $productionId) {
            if (filter_var($productionId, FILTER_VALIDATE_INT) === false || (int) $productionId < 1) {
                throw ValidationException::withMessages([
                    'productions' => 'Selected production identifiers are invalid.',
                ]);
            }

            $ids[] = (int) $productionId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, ProductionRun>  $productions
     */
    private function assertProductionStatuses(Collection $productions): void
    {
        foreach ($productions as $production) {
            if (! in_array($production->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'productions' => 'Only planned or stock-prepared productions can reserve stock.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, list<array{stock_lot_id: int|string, quantity: int|float|string}>>  $manualAllocations
     * @param  Collection<int, ProductionRequirement>  $requirements
     */
    private function assertManualRequirementKeys(array $manualAllocations, Collection $requirements): void
    {
        $requirementIds = $requirements->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        foreach (array_keys($manualAllocations) as $requirementId) {
            if (! in_array((string) $requirementId, $requirementIds, true)) {
                throw ValidationException::withMessages([
                    'requirements' => 'Manual allocations must belong to the selected productions.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, ProductionRequirement>  $requirements
     * @return Collection<int, StockLot>
     */
    private function lockSubjectLots(Workspace $workspace, Collection $requirements): Collection
    {
        $ingredientIds = $requirements->whereNotNull('ingredient_id')->pluck('ingredient_id')->unique()->values();
        $packagingIds = $requirements->whereNotNull('packaging_item_id')->pluck('packaging_item_id')->unique()->values();

        return StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', StockLotStatus::Released)
            ->where(function (Builder $query) use ($ingredientIds, $packagingIds): void {
                $query
                    ->when($ingredientIds->isNotEmpty(), fn (Builder $nested): Builder => $nested->whereIn('ingredient_id', $ingredientIds)->whereNull('packaging_item_id'))
                    ->when($packagingIds->isNotEmpty(), fn (Builder $nested): Builder => $nested->orWhereIn('packaging_item_id', $packagingIds)->whereNull('ingredient_id'));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, ProductionRun>  $productions
     * @param  Collection<int, ProductionRequirement>  $requirements
     * @param  Collection<int, StockLot>  $lots
     */
    private function lockCompetingReservations(Collection $productions, Collection $requirements, Collection $lots): void
    {
        $productionIds = $productions->pluck('id');
        $requirementIds = $requirements->pluck('id');
        $lotIds = $lots->pluck('id');

        StockReservation::query()
            ->where('status', StockReservationStatus::Active)
            ->where(function (Builder $query) use ($lotIds, $productionIds, $requirementIds): void {
                $query
                    ->whereIn('production_run_id', $productionIds)
                    ->orWhereIn('production_requirement_id', $requirementIds)
                    ->orWhereIn('stock_lot_id', $lotIds);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, ProductionRequirement>  $requirements
     * @param  array<string, list<array{stock_lot_id: int|string, quantity: int|float|string}>>  $manualAllocations
     * @return list<array{requirement: ProductionRequirement, lot: StockLot, quantity: string}>
     */
    private function buildAllocations(Collection $requirements, array $manualAllocations): array
    {
        $allocations = [];

        foreach ($requirements as $requirement) {
            $manual = array_key_exists((string) $requirement->id, $manualAllocations)
                ? $manualAllocations[(string) $requirement->id]
                : null;
            $proposal = $manual === null
                ? $this->proposal->forRequirement($requirement)
                : $this->proposal->forRequirement($requirement, $this->manualLotIds($manual));

            if ($manual === null) {
                if (bccomp($proposal['missing'], '0', 9) > 0) {
                    throw ValidationException::withMessages([
                        'requirements.'.$requirement->id => 'There is not enough eligible stock to prepare this production.',
                    ]);
                }

                foreach ($proposal['allocations'] as $allocation) {
                    $allocations[] = [
                        'requirement' => $requirement,
                        'lot' => $allocation['lot'],
                        'quantity' => $allocation['quantity'],
                    ];
                }

                continue;
            }

            $allocations = [...$allocations, ...$this->manualRequirementAllocations($requirement, $manual, $proposal)];
        }

        return $allocations;
    }

    /**
     * @param  list<array{stock_lot_id: int|string, quantity: int|float|string}>  $manual
     * @return list<int>
     */
    private function manualLotIds(array $manual): array
    {
        $ids = [];

        foreach ($manual as $row) {
            if (! is_array($row) || ! array_key_exists('stock_lot_id', $row)) {
                throw ValidationException::withMessages([
                    'allocations' => 'Manual stock allocations are invalid.',
                ]);
            }

            $id = filter_var($row['stock_lot_id'], FILTER_VALIDATE_INT);

            if ($id === false || $id < 1 || in_array($id, $ids, true)) {
                throw ValidationException::withMessages([
                    'allocations' => 'Manual stock allocations must use each lot once.',
                ]);
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Choose at least one stock lot for a manual allocation.',
            ]);
        }

        return $ids;
    }

    /**
     * @param  list<array{stock_lot_id: int|string, quantity: int|float|string}>  $manual
     * @param  array{remaining: string, eligible_lots: list<array{lot: StockLot, available: string}>}  $proposal
     * @return list<array{requirement: ProductionRequirement, lot: StockLot, quantity: string}>
     */
    private function manualRequirementAllocations(ProductionRequirement $requirement, array $manual, array $proposal): array
    {
        $availableByLot = collect($proposal['eligible_lots'])->keyBy(fn (array $row): int => $row['lot']->id);
        $total = '0.000000000';
        $allocations = [];

        foreach ($manual as $row) {
            $lotId = (int) $row['stock_lot_id'];
            $quantity = $this->quantity($row['quantity']);
            $available = $availableByLot->get($lotId);

            if ($available === null || bccomp($quantity, $available['available'], 9) > 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'A selected stock lot does not have enough eligible quantity.',
                ]);
            }

            if ($requirement->packaging_item_id !== null && ! preg_match('/^\d+(?:\.0{1,9})?$/', $quantity)) {
                throw ValidationException::withMessages([
                    'allocations' => 'Packaging reservations must use whole units.',
                ]);
            }

            $total = bcadd($total, $quantity, 9);
            $allocations[] = [
                'requirement' => $requirement,
                'lot' => $available['lot'],
                'quantity' => $quantity,
            ];
        }

        if (bccomp($total, $proposal['remaining'], 9) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Manual stock allocations must exactly cover the remaining requirement.',
            ]);
        }

        return $allocations;
    }

    private function quantity(int|float|string $quantity): string
    {
        $normalized = trim((string) $quantity);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1 || bccomp($normalized, '0', 9) <= 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Reservation quantities must be greater than zero.',
            ]);
        }

        return bcadd($normalized, '0', 9);
    }

    /**
     * @param  array{requirement: ProductionRequirement, lot: StockLot, quantity: string}  $allocation
     */
    private function createReservation(
        Workspace $workspace,
        array $allocation,
        string $idempotencyKey,
        User $actor,
    ): void {
        $key = $this->reservationKey($idempotencyKey, $allocation['requirement'], $allocation['lot']);
        $existing = StockReservation::query()
            ->where('workspace_id', $workspace->id)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof StockReservation) {
            if ($existing->status === StockReservationStatus::Active
                && bccomp((string) $existing->quantity, $allocation['quantity'], 9) === 0) {
                return;
            }

            throw ValidationException::withMessages([
                'idempotencyKey' => 'This preparation key was already used for a different reservation.',
            ]);
        }

        StockReservation::query()->create([
            'workspace_id' => $workspace->id,
            'production_run_id' => $allocation['requirement']->production_run_id,
            'production_requirement_id' => $allocation['requirement']->id,
            'stock_lot_id' => $allocation['lot']->id,
            'quantity' => $allocation['quantity'],
            'status' => StockReservationStatus::Active,
            'created_by_user_id' => $actor->id,
            'idempotency_key' => $key,
        ]);
    }

    private function reservationKey(string $idempotencyKey, ProductionRequirement $requirement, StockLot $lot): string
    {
        return substr($idempotencyKey.':'.$requirement->id.':'.$lot->id, 0, 191);
    }

    /**
     * @param  Collection<int, ProductionRequirement>  $requirements
     */
    private function productionIsFullyReserved(Collection $requirements): bool
    {
        foreach ($requirements as $requirement) {
            $required = $requirement->ingredient_id !== null
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;

            if (bccomp($this->activeRequirementQuantity($requirement), $required, 9) < 0) {
                return false;
            }
        }

        return true;
    }

    private function activeRequirementQuantity(ProductionRequirement $requirement): string
    {
        $quantity = '0.000000000';

        foreach ($requirement->reservations()->where('status', StockReservationStatus::Active)->get(['quantity']) as $reservation) {
            $quantity = bcadd($quantity, (string) $reservation->quantity, 9);
        }

        return $quantity;
    }
}
