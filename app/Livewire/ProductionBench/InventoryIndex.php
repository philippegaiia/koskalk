<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class InventoryIndex extends Component
{
    public ?int $supplierListingId = null;

    public string $quantity = '';

    public string $unit = 'kg';

    public string $pricePerUnit = '';

    public string $currency = '';

    public string $supplierBatchNumber = '';

    public string $stockedAt = '';

    public string $expiresAt = '';

    public string $notes = '';

    public ?string $savedLotCode = null;

    public function mount(): void
    {
        $workspace = $this->workspace();
        $this->unit = $workspace->mass_display_system->priceUnit()->value;
        $this->currency = $workspace->default_currency;
    }

    public function updatedSupplierListingId(): void
    {
        $listing = $this->selectedListing();

        if (! $listing instanceof SupplierListing) {
            return;
        }

        $this->currency = $listing->currency;

        if ($listing->packaging_item_id !== null) {
            $this->unit = 'count';
            $this->pricePerUnit = bcdiv($listing->total_price, $listing->canonical_quantity_per_purchase_format, 9);

            return;
        }

        $this->unit = $this->workspace()->mass_display_system->priceUnit()->value;
        $pricePerGram = bcdiv($listing->total_price, $listing->canonical_quantity_per_purchase_format, 12);
        $gramsPerDisplayUnit = app(MassConverter::class)->toGrams('1', $this->unit);
        $this->pricePerUnit = bcmul($pricePerGram, $gramsPerDisplayUnit, 9);
    }

    public function createOpeningStock(CreateOpeningStockLot $action): void
    {
        $workspace = $this->workspace();
        $listing = $this->selectedListing() ?? abort(404);
        $pricePerCanonicalUnit = $listing->ingredient_id !== null
            ? bcdiv($this->pricePerUnit, app(MassConverter::class)->toGrams('1', $this->unit), 12)
            : $this->pricePerUnit;

        $lot = $action->handle(
            actor: $this->user(),
            workspace: $workspace,
            listing: $listing,
            quantity: $this->quantity,
            unit: $this->unit,
            pricePerCanonicalUnit: $pricePerCanonicalUnit,
            currency: $this->currency,
            idempotencyKey: (string) Str::uuid(),
            supplierBatchNumber: filled($this->supplierBatchNumber) ? $this->supplierBatchNumber : null,
            stockedAt: filled($this->stockedAt) ? $this->stockedAt : null,
            expiresAt: filled($this->expiresAt) ? $this->expiresAt : null,
            notes: filled($this->notes) ? $this->notes : null,
        );

        $this->savedLotCode = $lot->internal_lot_code;
        $this->reset('supplierListingId', 'quantity', 'pricePerUnit', 'supplierBatchNumber', 'expiresAt', 'notes');
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
            'supplierListings' => SupplierListing::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with(['supplier', 'ingredient.translations', 'packagingItem'])
                ->orderBy('supplier_id')
                ->orderBy('purchase_format')
                ->get(),
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

    private function selectedListing(): ?SupplierListing
    {
        if ($this->supplierListingId === null) {
            return null;
        }

        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->find($this->supplierListingId);
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
