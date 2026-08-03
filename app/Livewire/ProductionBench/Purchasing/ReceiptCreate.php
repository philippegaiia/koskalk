<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\ReceiveDirectGoodsReceipt;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\StockUnitKind;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class ReceiptCreate extends Component
{
    #[Url(except: '')]
    public string $source = '';

    #[Url(as: 'order', except: '')]
    public ?string $orderPublicId = null;

    #[Locked]
    public string $idempotencyKey = '';

    public ?int $supplierId = null;

    public string $orderSearch = '';

    public string $listingSearch = '';

    public string $receivedAt = '';

    public string $deliveryReference = '';

    public string $notes = '';

    /** @var array<int, bool> */
    public array $selected = [];

    /** @var array<int, array<string, mixed>> */
    public array $lineInputs = [];

    /** @var array<int, bool> */
    public array $actualQuantityEdited = [];

    /** @var array<int, string> */
    #[Locked]
    public array $nominalCanonicalQuantities = [];

    /** @var array<int, string> */
    #[Locked]
    public array $lineUnitKinds = [];

    public function mount(ProductionBenchAccess $access): void
    {
        abort_unless($access->isActive($this->workspace()), 403);
        $access->assertWritable($this->user(), $this->workspace());
        $this->idempotencyKey = (string) Str::uuid();
        $this->receivedAt = now()->toDateString();

        if ($this->source === GoodsReceiptSource::PurchaseOrder->value && filled($this->orderPublicId)) {
            $this->initializeOrderLines();
        }
    }

    public function chooseSource(string $source): void
    {
        $selectedSource = GoodsReceiptSource::tryFrom($source);

        if (! $selectedSource instanceof GoodsReceiptSource) {
            $this->addError('source', __('production_bench.receipt.invalid_source'));

            return;
        }

        $this->source = $selectedSource->value;
        $this->orderPublicId = null;
        $this->supplierId = null;
        $this->orderSearch = '';
        $this->listingSearch = '';
        $this->selected = [];
        $this->lineInputs = [];
        $this->actualQuantityEdited = [];
        $this->nominalCanonicalQuantities = [];
        $this->lineUnitKinds = [];
        $this->resetValidation();
    }

    public function updatedOrderPublicId(): void
    {
        $this->selected = [];
        $this->lineInputs = [];
        $this->actualQuantityEdited = [];
        $this->nominalCanonicalQuantities = [];
        $this->lineUnitKinds = [];
        $this->initializeOrderLines();
    }

    public function updatedSupplierId(): void
    {
        $this->listingSearch = '';
        $this->selected = [];
        $this->lineInputs = [];
        $this->actualQuantityEdited = [];
        $this->nominalCanonicalQuantities = [];
        $this->lineUnitKinds = [];

        foreach ($this->directListings() as $listing) {
            $this->lineInputs[$listing->id] = $this->defaultListingInput($listing);
            $this->actualQuantityEdited[$listing->id] = false;
            $this->nominalCanonicalQuantities[$listing->id] = $listing->canonical_quantity_per_purchase_format;
            $this->lineUnitKinds[$listing->id] = $listing->unit_kind->value;
        }
    }

    public function updatedListingSearch(): void
    {
        foreach ($this->directListings() as $listing) {
            if (isset($this->lineInputs[$listing->id])) {
                continue;
            }

            $this->lineInputs[$listing->id] = $this->defaultListingInput($listing);
            $this->actualQuantityEdited[$listing->id] = false;
            $this->nominalCanonicalQuantities[$listing->id] = $listing->canonical_quantity_per_purchase_format;
            $this->lineUnitKinds[$listing->id] = $listing->unit_kind->value;
        }
    }

    public function updatedLineInputs(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$lineId, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (! is_numeric($lineId) || $field === null) {
            return;
        }

        $lineId = (int) $lineId;

        if ($field === 'actual_quantity') {
            $this->actualQuantityEdited[$lineId] = true;

            return;
        }

        if (
            in_array($field, ['packs_received', 'actual_unit'], true)
            && ! ($this->actualQuantityEdited[$lineId] ?? false)
        ) {
            $this->synchronizeNominalActualQuantity($lineId);
        }
    }

    public function post(
        ReceivePurchaseOrder $receivePurchaseOrder,
        ReceiveDirectGoodsReceipt $receiveDirectGoodsReceipt,
    ): void {
        $this->validate([
            'source' => ['required', Rule::enum(GoodsReceiptSource::class)],
            'receivedAt' => ['required', 'date_format:Y-m-d'],
            'deliveryReference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $receipt = GoodsReceiptSource::from($this->source) === GoodsReceiptSource::PurchaseOrder
            ? $this->postPurchaseOrder($receivePurchaseOrder)
            : $this->postDirect($receiveDirectGoodsReceipt);

        session()->flash('status', __('production_bench.receipt.posted'));
        $this->redirectRoute('production-bench.purchasing.receipts.show', ['goodsReceipt' => $receipt], navigate: true);
    }

    public function render(): View
    {
        $orders = $this->eligibleOrders();

        return view('livewire.production-bench.purchasing.receipt-create', [
            'orders' => $orders,
            'selectedOrder' => $this->selectedOrder($orders),
            'suppliers' => Supplier::query()
                ->where('workspace_id', $this->workspace()->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'listings' => $this->directListings(),
        ]);
    }

    private function postPurchaseOrder(ReceivePurchaseOrder $action): GoodsReceipt
    {
        $order = $this->selectedOrder();

        if (! $order instanceof PurchaseOrder) {
            throw ValidationException::withMessages([
                'orderPublicId' => __('production_bench.receipt.order_unavailable'),
            ]);
        }

        $lines = $this->selectedLineInputs($order->lines->keyBy('id'));

        return $action->handle(
            actor: $this->user(),
            order: $order,
            idempotencyKey: $this->idempotencyKey,
            deliveryReference: $this->deliveryReference ?: null,
            lines: $lines->map(fn (array $input): array => [
                'order_line' => $input['model'],
                'packs_received' => (int) $input['packs_received'],
                'actual_quantity' => (string) $input['actual_quantity'],
                'actual_unit' => (string) $input['actual_unit'],
                'receipt_price_basis' => ListingPriceBasis::from($input['receipt_price_basis']),
                'receipt_price_amount' => (string) $input['receipt_price_amount'],
                'receipt_price_unit' => filled($input['receipt_price_unit']) ? $input['receipt_price_unit'] : null,
                'currency' => strtoupper((string) $input['currency']),
                'supplier_batch_number' => filled($input['supplier_batch_number']) ? $input['supplier_batch_number'] : null,
                'expires_at' => filled($input['expires_at']) ? $input['expires_at'] : null,
                'notes' => filled($input['notes']) ? $input['notes'] : null,
            ])->values()->all(),
            receivedAt: $this->receivedAt,
            notes: $this->notes ?: null,
        );
    }

    private function postDirect(ReceiveDirectGoodsReceipt $action): GoodsReceipt
    {
        $validated = $this->validate([
            'supplierId' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('workspace_id', $this->workspace()->id),
            ],
        ]);
        $supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($validated['supplierId']);
        $lines = $this->selectedLineInputs($this->selectedDirectListings($supplier)->keyBy('id'));

        return $action->handle(
            actor: $this->user(),
            workspace: $this->workspace(),
            supplier: $supplier,
            idempotencyKey: $this->idempotencyKey,
            lines: $lines->map(fn (array $input): array => [
                'listing' => $input['model'],
                'packs_received' => (int) $input['packs_received'],
                'actual_quantity' => (string) $input['actual_quantity'],
                'actual_unit' => (string) $input['actual_unit'],
                'receipt_price_basis' => ListingPriceBasis::from($input['receipt_price_basis']),
                'receipt_price_amount' => (string) $input['receipt_price_amount'],
                'receipt_price_unit' => filled($input['receipt_price_unit']) ? $input['receipt_price_unit'] : null,
                'currency' => strtoupper((string) $input['currency']),
                'supplier_batch_number' => filled($input['supplier_batch_number']) ? $input['supplier_batch_number'] : null,
                'expires_at' => filled($input['expires_at']) ? $input['expires_at'] : null,
                'notes' => filled($input['notes']) ? $input['notes'] : null,
            ])->values()->all(),
            receivedAt: $this->receivedAt,
            deliveryReference: $this->deliveryReference ?: null,
            notes: $this->notes ?: null,
        );
    }

    /** @param Collection<int, PurchaseOrderLine|SupplierListing> $models */
    private function selectedLineInputs(Collection $models): Collection
    {
        $selectedIds = $this->selectedLineIds();

        if ($selectedIds->isEmpty() || $selectedIds->contains(fn (int $id): bool => ! $models->has($id))) {
            $this->addError('selected', __('production_bench.receipt.choose_line'));
            $this->throwFailure();
        }

        $rules = [];

        foreach ($selectedIds as $id) {
            $model = $models->get($id);
            $packsRules = ['required', 'integer', 'min:1'];

            if ($model instanceof PurchaseOrderLine) {
                $packsRules[] = 'max:'.$this->remainingPacks($model);
            }

            $rules["lineInputs.$id.packs_received"] = $packsRules;
            $rules["lineInputs.$id.actual_quantity"] = ['required', 'numeric', 'gt:0'];
            $rules["lineInputs.$id.actual_unit"] = [
                'required',
                Rule::in($model->unit_kind === StockUnitKind::Count ? ['count'] : ['g', 'kg', 'oz', 'lb']),
            ];
            $rules["lineInputs.$id.receipt_price_basis"] = ['required', Rule::enum(ListingPriceBasis::class)];
            $rules["lineInputs.$id.receipt_price_amount"] = ['required', 'numeric', 'gt:0'];
            $rules["lineInputs.$id.receipt_price_unit"] = ['nullable', 'string', 'max:24'];
            $rules["lineInputs.$id.currency"] = ['required', 'string', Rule::in([$model->currency])];
            $rules["lineInputs.$id.supplier_batch_number"] = ['nullable', 'string', 'max:120'];
            $rules["lineInputs.$id.expires_at"] = ['nullable', 'date_format:Y-m-d'];
            $rules["lineInputs.$id.notes"] = ['nullable', 'string', 'max:5000'];
        }

        $this->validate($rules);

        return $selectedIds->map(function (int $id) use ($models): array {
            $input = $this->lineInputs[$id];
            $input['model'] = $models->get($id);

            return $input;
        });
    }

    private function initializeOrderLines(): void
    {
        $order = $this->selectedOrder();

        if (! $order instanceof PurchaseOrder) {
            return;
        }

        foreach ($order->lines as $line) {
            if ($this->remainingPacks($line) > 0) {
                $this->lineInputs[$line->id] = $this->defaultOrderLineInput($line);
                $this->actualQuantityEdited[$line->id] = false;
                $this->nominalCanonicalQuantities[$line->id] = $line->canonical_quantity_per_pack;
                $this->lineUnitKinds[$line->id] = $line->unit_kind->value;
            }
        }
    }

    private function defaultOrderLineInput(PurchaseOrderLine $line): array
    {
        $actualUnit = $line->unit_kind === StockUnitKind::Count
            ? 'count'
            : ($line->supplierListing?->net_unit ?? 'kg');

        return [
            'packs_received' => 1,
            'actual_quantity' => $line->unit_kind === StockUnitKind::Count
                ? bcadd($line->canonical_quantity_per_pack, '0', 0)
                : app(MassConverter::class)->fromGrams($line->canonical_quantity_per_pack, $actualUnit),
            'actual_unit' => $actualUnit,
            'receipt_price_basis' => $line->price_basis?->value ?? ListingPriceBasis::TotalPurchaseFormat->value,
            'receipt_price_amount' => $line->price_amount ?? $line->pack_price,
            'receipt_price_unit' => $line->price_unit,
            'currency' => $line->currency,
            'supplier_batch_number' => '',
            'expires_at' => '',
            'notes' => '',
        ];
    }

    private function defaultListingInput(SupplierListing $listing): array
    {
        return [
            'packs_received' => 1,
            'actual_quantity' => $listing->net_quantity,
            'actual_unit' => $listing->unit_kind === StockUnitKind::Count ? 'count' : $listing->net_unit,
            'receipt_price_basis' => $listing->price_basis->value,
            'receipt_price_amount' => $listing->price_amount,
            'receipt_price_unit' => $listing->price_unit,
            'currency' => $listing->currency,
            'supplier_batch_number' => '',
            'expires_at' => '',
            'notes' => '',
        ];
    }

    private function eligibleOrders(): Collection
    {
        return PurchaseOrder::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('stage', ProcurementStage::PurchaseOrder)
            ->whereNotNull('issued_at')
            ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived])
            ->whereHas('lines', fn (Builder $query): Builder => $query->whereRaw(
                'purchase_order_lines.ordered_packs > (select coalesce(sum(goods_receipt_lines.packs_received), 0) from goods_receipt_lines inner join goods_receipts on goods_receipts.id = goods_receipt_lines.goods_receipt_id where goods_receipt_lines.purchase_order_line_id = purchase_order_lines.id and goods_receipts.status = ?)',
                [GoodsReceiptStatus::Posted->value],
            ))
            ->when(filled($this->orderSearch), function (Builder $query): Builder {
                $search = trim($this->orderSearch);

                return $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->whereLike('reference', "%{$search}%")
                        ->orWhereHas('supplier', fn (Builder $supplierQuery): Builder => $supplierQuery
                            ->whereLike('name', "%{$search}%"));

                    if (filled($this->orderPublicId)) {
                        $searchQuery->orWhere('public_id', $this->orderPublicId);
                    }
                });
            })
            ->with(['supplier', 'lines.supplierListing', 'lines.ingredient.translations', 'lines.packagingItem', 'lines.receiptLines.goodsReceipt'])
            ->when(
                filled($this->orderPublicId),
                fn (Builder $query): Builder => $query->orderByRaw('case when public_id = ? then 0 else 1 end', [$this->orderPublicId]),
            )
            ->latest('issued_at')
            ->limit(100)
            ->get()
            ->values();
    }

    private function selectedOrder(?Collection $orders = null): ?PurchaseOrder
    {
        if (blank($this->orderPublicId)) {
            return null;
        }

        return ($orders ?? $this->eligibleOrders())->firstWhere('public_id', $this->orderPublicId);
    }

    private function synchronizeNominalActualQuantity(int $lineId): void
    {
        $input = $this->lineInputs[$lineId] ?? null;

        if (! is_array($input) || ! is_numeric($input['packs_received'] ?? null)) {
            return;
        }

        $canonicalPerFormat = $this->nominalCanonicalQuantities[$lineId] ?? null;
        $unitKind = StockUnitKind::tryFrom($this->lineUnitKinds[$lineId] ?? '');

        if ($canonicalPerFormat === null || ! $unitKind instanceof StockUnitKind) {
            return;
        }

        $canonicalTotal = bcmul($canonicalPerFormat, (string) $input['packs_received'], 9);

        $this->lineInputs[$lineId]['actual_quantity'] = $unitKind === StockUnitKind::Count
            ? bcadd($canonicalTotal, '0', 0)
            : app(MassConverter::class)->fromGrams($canonicalTotal, (string) $input['actual_unit']);
    }

    private function directListings(): Collection
    {
        if ($this->source !== GoodsReceiptSource::Direct->value || $this->supplierId === null) {
            return collect();
        }

        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('supplier_id', $this->supplierId)
            ->where('is_active', true)
            ->when(filled($this->listingSearch), function (Builder $query): Builder {
                $search = trim($this->listingSearch);

                return $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->whereLike('purchase_format', "%{$search}%")
                        ->orWhereLike('supplier_sku', "%{$search}%")
                        ->orWhereLike('supplier_item_name', "%{$search}%");
                });
            })
            ->with(['ingredient.translations', 'packagingItem'])
            ->orderBy('purchase_format')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, SupplierListing> */
    private function selectedDirectListings(Supplier $supplier): Collection
    {
        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->whereIn('id', $this->selectedLineIds())
            ->with(['ingredient.translations', 'packagingItem'])
            ->get();
    }

    /** @return Collection<int, int> */
    private function selectedLineIds(): Collection
    {
        return collect($this->selected)
            ->filter(fn (mixed $selected): bool => (bool) $selected)
            ->keys()
            ->map(fn (int|string $id): int => (int) $id)
            ->values();
    }

    private function remainingPacks(PurchaseOrderLine $line): int
    {
        return $line->ordered_packs - $this->receivedPacks($line);
    }

    private function receivedPacks(PurchaseOrderLine $line): int
    {
        return (int) $line->receiptLines
            ->filter(fn ($receiptLine): bool => $receiptLine->goodsReceipt?->status === GoodsReceiptStatus::Posted)
            ->sum('packs_received');
    }

    private function throwFailure(): never
    {
        throw ValidationException::withMessages([
            'selected' => __('production_bench.receipt.choose_line'),
        ]);
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
