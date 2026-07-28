<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\StockLotStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class InventoryIndex extends Component
{
    public string $subjectType = 'ingredient';

    public ?int $subjectId = null;

    public string $quantity = '';

    public string $unit = 'kg';

    public string $status = 'released';

    public string $supplierBatchNumber = '';

    public string $stockedAt = '';

    public string $expiresAt = '';

    public bool $provenanceComplete = false;

    public string $notes = '';

    public ?string $savedLotCode = null;

    public function updatedSubjectType(): void
    {
        $this->subjectId = null;
        $this->unit = $this->subjectType === 'packaging' ? 'count' : 'kg';
    }

    public function createOpeningStock(CreateOpeningStockLot $action): void
    {
        $workspace = $this->workspace();
        $subject = $this->subjectType === 'packaging'
            ? UserPackagingItem::query()
                ->where('user_id', $workspace->owner_user_id)
                ->findOrFail($this->subjectId)
            : Ingredient::query()->findOrFail($this->subjectId);

        $lot = $action->handle(
            actor: $this->user(),
            workspace: $workspace,
            subject: $subject,
            quantity: $this->quantity,
            unit: $this->unit,
            status: StockLotStatus::from($this->status),
            idempotencyKey: (string) Str::uuid(),
            supplierBatchNumber: filled($this->supplierBatchNumber) ? $this->supplierBatchNumber : null,
            stockedAt: filled($this->stockedAt) ? $this->stockedAt : null,
            expiresAt: filled($this->expiresAt) ? $this->expiresAt : null,
            provenanceComplete: $this->provenanceComplete,
            notes: filled($this->notes) ? $this->notes : null,
        );

        $this->savedLotCode = $lot->internal_lot_code;
        $this->reset('subjectId', 'quantity', 'supplierBatchNumber', 'expiresAt', 'notes', 'provenanceComplete');
    }

    public function release(int $lotId, ReleaseStockLot $action): void
    {
        $action->handle($this->user(), $this->lot($lotId));
    }

    public function quarantine(int $lotId, QuarantineStockLot $action): void
    {
        $action->handle($this->user(), $this->lot($lotId));
    }

    public function render(
        ProductionBenchAccess $access,
        StockPositionService $positions,
        MassConverter $massConverter,
    ): View {
        $workspace = $this->workspace();
        $displayUnit = $workspace->mass_display_system->priceUnit()->value;
        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->with(['ingredient.translations', 'packagingItem'])
            ->latest('stocked_at')
            ->latest('id')
            ->get()
            ->map(function (StockLot $lot) use ($positions, $massConverter, $displayUnit): array {
                $stock = $positions->forLot($lot);

                return [
                    'lot' => $lot,
                    'positions' => collect($stock)->map(
                        fn (string $quantity): string => $lot->ingredient_id !== null
                            ? number_format((float) $massConverter->fromGrams($quantity, $displayUnit), 2)
                            : number_format((float) $quantity, 0),
                    )->all(),
                ];
            });

        return view('livewire.production-bench.inventory-index', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('display_name')->get(),
            'packagingItems' => UserPackagingItem::query()->where('user_id', $workspace->owner_user_id)->orderBy('name')->get(),
            'lots' => $lots,
            'displayUnit' => $displayUnit,
        ]);
    }

    private function lot(int $lotId): StockLot
    {
        return StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($lotId);
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
