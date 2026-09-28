<?php

namespace App\Services\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\Workspace;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;

final class ProductionPickingPrintDataBuilder
{
    public function __construct(private readonly StockReservationProposalService $proposals) {}

    /** @param array{runs: list<string>, allocations: list<array{requirement_id: int, stock_lot_id: int, quantity: string}>} $input
     * @return list<array<string, mixed>>
     */
    public function build(Workspace $workspace, array $input): array
    {
        $this->check(count($input['runs']) <= 100 && count($input['allocations']) <= 1000, 'limit');
        $runs = ProductionRun::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $input['runs'])->withCount('requirements')->orderBy('planned_for')->orderBy('id')->get();
        abort_unless($runs->count() === count($input['runs']), 404);
        $this->check($runs->sum('requirements_count') <= 1000, 'limit');
        foreach ($runs as $run) {
            $this->check(in_array($run->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true));
        }
        $runs->load('requirements');
        $requirements = $runs->flatMap->requirements->keyBy('id');
        $rowsByRequirement = collect($input['allocations'])->groupBy('requirement_id');
        foreach ($rowsByRequirement->keys() as $id) {
            $this->check($requirements->has($id));
        }
        $lotTotals = [];
        $rowCount = 0;
        $documents = [];
        foreach ($runs as $run) {
            $rows = [];
            foreach ($run->requirements as $requirement) {
                $requirement->setRelation('productionRun', $run);
                $proposal = $this->proposals->forRequirement($requirement);
                $eligible = collect($proposal['eligible_lots'])->keyBy(fn (array $row): int => $row['lot']->id);
                $proposed = '0';
                $seen = [];
                foreach ($rowsByRequirement->get($requirement->id, []) as $allocation) {
                    $id = (int) $allocation['stock_lot_id'];
                    $quantity = NumberLocale::normalizeDecimalString($allocation['quantity']);
                    $this->check(! isset($seen[$id]) && $quantity !== null && preg_match('/^\d{1,18}(?:\.\d{1,9})?$/', $quantity) === 1 && bccomp($quantity, '0', 9) > 0);
                    $seen[$id] = true;
                    $this->check($eligible->has($id));
                    $lot = $eligible[$id]['lot'];
                    $this->check($lot->unit_kind === ($requirement->ingredient_id !== null ? StockUnitKind::Mass : StockUnitKind::Count));
                    $this->check($requirement->ingredient_id !== null || bccomp($quantity, bcadd($quantity, '0', 0), 9) === 0);
                    $proposed = bcadd($proposed, $quantity, 9);
                    $lotTotals[$id] = bcadd($lotTotals[$id] ?? '0', $quantity, 9);
                    $this->check(bccomp($lotTotals[$id], $eligible[$id]['available'], 9) <= 0);
                    $rows[] = $this->row($requirement, $lot, $quantity, 'proposed', $workspace);
                }
                $this->check(bccomp($proposed, $proposal['remaining'], 9) <= 0);
                $reservations = $requirement->reservations()->where('status', StockReservationStatus::Active)->with($workspace->uses_storage_locations ? 'stockLot.storageLocation' : 'stockLot')->limit(1001)->get();
                foreach ($reservations as $reservation) {
                    $rows[] = $this->row($requirement, $reservation->stockLot, $reservation->quantity, 'reserved', $workspace);
                }
                $missing = bcsub($proposal['remaining'], $proposed, 9);
                if (bccomp($missing, '0', 9) > 0) {
                    $rows[] = $this->row($requirement, null, $missing, 'shortage', $workspace);
                }
                $this->check($rowCount + count($rows) <= 1000, 'limit');
            }
            $rowCount += count($rows);
            $documents[] = ['identifier' => $run->displayIdentifier(), 'formula' => $run->recipe_name_snapshot, 'rows' => $rows];
        }

        return $documents;
    }

    /** @return array<string, mixed> */
    private function row(ProductionRequirement $requirement, ?StockLot $lot, string $quantity, string $status, Workspace $workspace): array
    {
        return ['material' => $requirement->subject_name_snapshot, 'code' => $requirement->material_code_snapshot, 'lot' => $lot?->internal_lot_code, 'supplier_batch' => $lot?->supplier_batch_number, 'quantity' => $quantity, 'unit' => $requirement->ingredient_id !== null ? 'g' : __('production_bench.inventory.count'), 'status' => $status, 'location' => $workspace->uses_storage_locations ? $lot?->storageLocation?->name : null];
    }

    private function check(bool $valid, string $key = 'adjust'): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['allocations' => __('production_bench.picking.validation.'.$key)]);
        }
    }
}
