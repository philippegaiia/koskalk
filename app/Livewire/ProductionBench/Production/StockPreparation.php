<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\PrepareProductionStock;
use App\Livewire\Concerns\InteractsWithProductionEditing;
use App\Livewire\Concerns\InteractsWithProductionWorkspace;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\Production\StockReservationProposalService;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class StockPreparation extends Component
{
    use InteractsWithProductionEditing;
    use InteractsWithProductionWorkspace;

    /** @var list<int> */
    #[Locked]
    public array $productionIds = [];

    /** @var array<string, bool> */
    public array $manualMode = [];

    /** @var array<string, array<string, string>> */
    public array $manualQuantities = [];

    #[Locked]
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
            abort_if(count(explode(',', $queryIds)) > 100, 422);
            foreach (explode(',', $queryIds) as $queryId) {
                abort_unless(ctype_digit($queryId) && (int) $queryId > 0, 422);
                $ids[] = (int) $queryId;
            }
        }

        abort_if(count($ids) < 1 || count($ids) > 100, 422);
        $this->productionIds = collect($ids)->unique()->sort()->values()->all();
        abort_unless(ProductionRun::query()->where('workspace_id', $this->workspace()->id)->whereIn('id', $this->productionIds)->count() === count($this->productionIds), 404);
        $this->idempotencyKey = (string) Str::uuid();
        $this->initializeProductionEditing($this->productionIds);
    }

    public function toggleManual(int $requirementId): void
    {
        $key = (string) $requirementId;
        $this->manualMode[$key] = ! ($this->manualMode[$key] ?? false);
    }

    public function confirm(PrepareProductionStock $prepareProductionStock): void
    {
        try {
            $context = $this->productionEditingContext();
            $prepared = $prepareProductionStock->handle(
                actor: $this->user(),
                productionIds: $this->productionIds,
                idempotencyKey: $this->idempotencyKey,
                manualAllocations: $this->manualAllocations(),
                editing: $context,
            );
            $this->acknowledgeProductionMutation($context);
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
        ProductionHelpTopics $helpTopics,
        ProductionBenchAccess $access,
        StockReservationProposalService $proposalService,
    ): View {
        $workspace = $this->workspace();
        $productions = collect($this->editingPresentation)->map(fn (array $snapshot): ProductionRun => $this->productionModelFromSnapshot($snapshot))->values();

        return view('livewire.production-bench.production.stock-preparation', [
            'editingPayload' => $this->productionEditingPayload(),
            'contextualHelp' => $helpTopics->resolve('stock', app()->getLocale()),
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

    private function productionSnapshotQuery(): Builder
    {
        return ProductionRun::query()->with(['workspace', 'requirements.productionRun.workspace']);
    }

    private function productionDraftGroupsFromSnapshot(Collection $productions): array
    {
        return ['allocations' => ['manualMode' => [], 'manualQuantities' => []]];
    }

    private function productionDraftGroups(): array
    {
        return ['allocations' => ['manualMode' => $this->manualMode, 'manualQuantities' => $this->manualQuantities]];
    }

    #[Renderless]
    public function executeEditingCommand(string $method, array $arguments = [], ?array $submitted = null, ?string $group = null): array
    {
        abort_unless($method === 'confirm' && $arguments === [] && $group === 'allocations' && $submitted !== null, 422);
        validator($submitted, [
            'manualMode' => ['present', 'array', 'max:1000'], 'manualMode.*' => ['boolean'],
            'manualQuantities' => ['present', 'array', 'max:1000'], 'manualQuantities.*' => ['array', 'max:100'], 'manualQuantities.*.*' => ['string', 'max:100'],
        ])->validate();
        $allowedRequirements = collect($this->editingPresentation)->flatMap(fn (array $snapshot): Collection => $this->productionModelFromSnapshot($snapshot)->requirements)->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        abort_unless(array_diff(array_map('strval', array_keys($submitted['manualMode'])), $allowedRequirements) === []
            && array_diff(array_map('strval', array_keys($submitted['manualQuantities'])), $allowedRequirements) === [], 422);
        $this->manualMode = $submitted['manualMode'];
        $this->manualQuantities = $submitted['manualQuantities'];
        $this->resetErrorBag();
        $this->productionCommandAcknowledgment = [];
        $this->confirm(app(PrepareProductionStock::class));

        return ['ok' => $this->productionCommandAcknowledgment !== [] && $this->getErrorBag()->isEmpty(),
            'revisions' => $this->productionCommandAcknowledgment, 'canonical' => $this->productionDraftGroups()['allocations'],
            'state' => $this->observeProductionEditing(), 'errors' => $this->getErrorBag()->toArray()];
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->productionWorkspace();
    }
}
