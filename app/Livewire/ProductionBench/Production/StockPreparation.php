<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\PrepareProductionStock;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\StockReservationProposalService;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class StockPreparation extends Component
{
    /** @var list<int> */
    public array $productionIds = [];

    /** @var array<string, bool> */
    public array $manualMode = [];

    /** @var array<string, array<string, string>> */
    public array $manualQuantities = [];

    public string $idempotencyKey = '';

    public function mount(string|int|ProductionRun|null $productionRun = null): void
    {
        $ids = [];

        if ($productionRun instanceof ProductionRun) {
            $ids[] = $productionRun->id;
        } elseif ($productionRun !== null) {
            $ids[] = is_numeric($productionRun)
                ? (int) $productionRun
                : (int) (ProductionRun::query()->where('public_id', $productionRun)->value('id') ?? abort(404));
        }

        $queryIds = request()->query('ids');

        if (is_string($queryIds) && $queryIds !== '') {
            foreach (explode(',', $queryIds) as $queryId) {
                if (ctype_digit($queryId) && (int) $queryId > 0) {
                    $ids[] = (int) $queryId;
                }
            }
        }

        $this->productionIds = array_values(array_unique($ids));
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function toggleManual(int $requirementId): void
    {
        $key = (string) $requirementId;
        $this->manualMode[$key] = ! ($this->manualMode[$key] ?? false);
    }

    public function confirm(PrepareProductionStock $prepareProductionStock): void
    {
        try {
            $prepared = $prepareProductionStock->handle(
                actor: $this->user(),
                productionIds: $this->productionIds,
                idempotencyKey: $this->idempotencyKey,
                manualAllocations: $this->manualAllocations(),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        session()->flash('status', __('production_bench.production.stock_prepared_success'));

        if (count($this->productionIds) === 1) {
            $this->redirectRoute('production-bench.production.show', ['productionRun' => $prepared[0]->public_id]);

            return;
        }

        $this->redirectRoute('production-bench.production.index');
    }

    public function render(
        ProductionBenchAccess $access,
        StockReservationProposalService $proposalService,
    ): View {
        $workspace = $this->workspace();
        $productions = ProductionRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $this->productionIds)
            ->with(['requirements.productionRun'])
            ->get()
            ->sortBy(fn (ProductionRun $production): array => [
                $production->planned_for?->toDateString() === null ? 1 : 0,
                $production->planned_for?->toDateString() ?? '',
                $production->id,
            ])
            ->values();

        if ($productions->count() !== count($this->productionIds)) {
            abort(404);
        }

        return view('livewire.production-bench.production.stock-preparation', [
            'workspace' => $workspace,
            'productions' => $productions,
            'proposals' => $proposalService->forProductions($productions),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
        ]);
    }

    /**
     * @return array<string, list<array{stock_lot_id: int, quantity: string}>>
     */
    private function manualAllocations(): array
    {
        $allocations = [];

        foreach ($this->manualMode as $requirementId => $enabled) {
            if (! $enabled) {
                continue;
            }

            $allocations[$requirementId] = [];

            foreach ($this->manualQuantities[$requirementId] ?? [] as $lotId => $quantity) {
                if (trim($quantity) === '') {
                    continue;
                }

                $allocations[$requirementId][] = [
                    'stock_lot_id' => (int) $lotId,
                    'quantity' => trim($quantity),
                ];
            }
        }

        return $allocations;
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
