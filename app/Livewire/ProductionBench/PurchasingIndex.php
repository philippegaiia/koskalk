<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\AttachProductionDocument;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Actions\Purchasing\SaveSupplier;
use App\ListingPriceBasis;
use App\MediaAssetType;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\PurchaseOrderStatus;
use App\Services\MassConverter;
use App\Services\MediaAssetUploadService;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockUnitKind;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class PurchasingIndex extends Component
{
    use WithFileUploads;

    public string $supplierName = '';

    public string $supplierCode = '';

    public string $supplierEmail = '';

    public ?int $listingSupplierId = null;

    public string $listingSubjectType = 'ingredient';

    public ?int $listingSubjectId = null;

    public string $listingSku = '';

    public string $listingDescription = '';

    public string $listingQuantity = '';

    public string $listingUnit = 'kg';

    public string $listingPackPrice = '';

    public ?int $orderListingId = null;

    public int $orderPacks = 1;

    public ?int $receiptOrderLineId = null;

    public int $receiptPacks = 1;

    public string $receiptQuantity = '';

    public string $receiptUnit = 'kg';

    public string $receiptSupplierBatch = '';

    public string $receiptDeliveryReference = '';

    public string $receiptStatus = 'quarantined';

    public mixed $documentUpload = null;

    public ?int $documentLotId = null;

    public string $documentType = 'certificate_of_analysis';

    public string $documentNote = '';

    public function createSupplier(SaveSupplier $saveSupplier): void
    {
        $workspace = $this->workspace();
        $supplier = $saveSupplier->handle($this->user(), $workspace, [
            'code' => $this->supplierCode,
            'name' => $this->supplierName,
            'email' => $this->supplierEmail,
            'default_currency' => $workspace->default_currency,
            'is_active' => true,
        ]);

        $this->listingSupplierId = $supplier->id;
        $this->reset('supplierCode', 'supplierName', 'supplierEmail');
    }

    public function updatedListingSubjectType(): void
    {
        $this->listingSubjectId = null;
        $this->listingUnit = $this->listingSubjectType === 'packaging' ? 'count' : 'kg';
    }

    public function createListing(ProductionBenchAccess $access, MassConverter $converter): void
    {
        $workspace = $this->workspace();
        $access->assertWritable($this->user(), $workspace);
        $supplier = Supplier::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($this->listingSupplierId);
        $isMass = $this->listingSubjectType === 'ingredient';
        $subject = $isMass
            ? Ingredient::query()->findOrFail($this->listingSubjectId)
            : PackagingItem::query()->where('workspace_id', $workspace->id)->findOrFail($this->listingSubjectId);

        Validator::make([
            'description' => $this->listingDescription,
            'quantity' => $this->listingQuantity,
            'price' => $this->listingPackPrice,
        ], [
            'description' => ['required', 'string', 'max:160'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ])->validate();

        if (! $isMass && preg_match('/^[1-9]\d*$/', $this->listingQuantity) !== 1) {
            throw ValidationException::withMessages(['listingQuantity' => 'Packaging quantity must be a whole number.']);
        }

        SupplierListing::query()->create([
            'workspace_id' => $workspace->id,
            'supplier_id' => $supplier->id,
            'ingredient_id' => $isMass ? $subject->id : null,
            'packaging_item_id' => $isMass ? null : $subject->id,
            'supplier_sku' => filled($this->listingSku) ? $this->listingSku : null,
            'purchase_format' => $this->listingDescription,
            'unit_kind' => $isMass ? StockUnitKind::Mass : StockUnitKind::Count,
            'canonical_quantity_per_purchase_format' => $isMass
                ? $converter->toGrams($this->listingQuantity, $this->listingUnit)
                : bcadd($this->listingQuantity, '0', 9),
            'net_quantity' => $this->listingQuantity,
            'net_unit' => $this->listingUnit,
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => $this->listingPackPrice,
            'total_price' => $this->listingPackPrice,
            'currency' => $workspace->default_currency,
            'minimum_packs' => 1,
            'is_active' => true,
        ]);

        $this->reset('listingSubjectId', 'listingSku', 'listingDescription', 'listingQuantity', 'listingPackPrice');
    }

    public function createOrder(CreatePurchaseOrder $action): void
    {
        $listing = SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with('supplier')
            ->findOrFail($this->orderListingId);

        $action->handle(
            actor: $this->user(),
            workspace: $this->workspace(),
            supplier: $listing->supplier,
            lines: [['listing' => $listing, 'packs' => $this->orderPacks]],
        );

        $this->reset('orderListingId');
        $this->orderPacks = 1;
    }

    public function placeOrder(int $orderId, PlacePurchaseOrder $action): void
    {
        $action->handle($this->user(), $this->order($orderId));
    }

    public function cancelOrder(int $orderId, CancelPurchaseOrder $action): void
    {
        $action->handle($this->user(), $this->order($orderId));
    }

    public function receiveDelivery(ReceivePurchaseOrder $action): void
    {
        $line = PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', fn ($query) => $query->where('workspace_id', $this->workspace()->id))
            ->findOrFail($this->receiptOrderLineId);

        $action->handle(
            actor: $this->user(),
            order: $line->purchaseOrder,
            idempotencyKey: (string) Str::uuid(),
            deliveryReference: filled($this->receiptDeliveryReference) ? $this->receiptDeliveryReference : null,
            lines: [[
                'order_line' => $line,
                'packs_received' => $this->receiptPacks,
                'actual_quantity' => $this->receiptQuantity,
                'actual_unit' => $this->receiptUnit,
                'supplier_batch_number' => filled($this->receiptSupplierBatch) ? $this->receiptSupplierBatch : null,
                'status' => StockLotStatus::from($this->receiptStatus),
            ]],
        );

        $this->reset('receiptOrderLineId', 'receiptQuantity', 'receiptSupplierBatch', 'receiptDeliveryReference');
        $this->receiptPacks = 1;
    }

    public function reverseReceipt(int $receiptId, ReverseGoodsReceipt $action): void
    {
        $receipt = GoodsReceipt::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($receiptId);
        $action->handle($this->user(), $receipt, 'Reversed from purchasing workspace');
    }

    public function uploadDocument(
        MediaAssetUploadService $uploads,
        AttachProductionDocument $attach,
    ): void {
        $this->validate([
            'documentUpload' => ['required', 'file', 'max:10240'],
            'documentLotId' => ['required', 'integer'],
            'documentType' => ['required', 'string'],
        ]);

        $lot = StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($this->documentLotId);
        $asset = $uploads->start(
            $this->user(),
            $this->workspace(),
            $this->documentUpload,
            [MediaAssetType::Image, MediaAssetType::Pdf],
        );
        $attach->handle(
            actor: $this->user(),
            documentable: $lot,
            asset: $asset,
            type: ProductionDocumentType::from($this->documentType),
            note: filled($this->documentNote) ? $this->documentNote : null,
        );

        $this->reset('documentUpload', 'documentLotId', 'documentNote');
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.purchasing-index', [
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'workspace' => $workspace,
            'suppliers' => Supplier::query()->where('workspace_id', $workspace->id)->with('listings')->orderBy('name')->get(),
            'listings' => SupplierListing::query()
                ->where('workspace_id', $workspace->id)
                ->with(['supplier', 'ingredient.translations', 'packagingItem'])
                ->latest('id')
                ->get(),
            'ingredients' => Ingredient::query()->where('is_active', true)->orderBy('display_name')->get(),
            'packagingItems' => PackagingItem::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'orders' => PurchaseOrder::query()
                ->where('workspace_id', $workspace->id)
                ->with(['supplier', 'lines.receiptLines', 'receipts.lines.stockLot'])
                ->latest('id')
                ->get(),
            'receivableLines' => PurchaseOrderLine::query()
                ->whereHas('purchaseOrder', fn ($query) => $query
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived]))
                ->with(['purchaseOrder', 'receiptLines'])
                ->get(),
            'receiptLots' => StockLot::query()
                ->where('workspace_id', $workspace->id)
                ->where('origin', 'purchase_receipt')
                ->with(['ingredient.translations', 'packagingItem', 'documents.mediaAsset'])
                ->latest('id')
                ->get(),
        ]);
    }

    private function order(int $orderId): PurchaseOrder
    {
        return PurchaseOrder::query()->where('workspace_id', $this->workspace()->id)->findOrFail($orderId);
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
