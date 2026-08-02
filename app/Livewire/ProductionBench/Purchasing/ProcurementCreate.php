<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\ConvertQuotationToPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProcurementCreate extends Component
{
    #[Locked]
    public string $stage;

    public ?int $supplierId = null;

    public ?string $quotationRequestPublicId = null;

    /** @var array<int, int|string|null> */
    public array $packs = [];

    public ?string $expectedAt = null;

    public ?string $notes = null;

    public function mount(ProductionBenchAccess $access, string $stage): void
    {
        $this->stage = ProcurementStage::from($stage)->value;
        $access->assertWritable($this->user(), $this->workspace());
    }

    public function updatedSupplierId(): void
    {
        $this->packs = [];
    }

    public function useQuotationRequest(ConvertQuotationToPurchaseOrder $convertQuotation): void
    {
        if (ProcurementStage::from($this->stage) !== ProcurementStage::PurchaseOrder) {
            abort(404);
        }

        $validated = $this->validate([
            'quotationRequestPublicId' => ['required', 'string'],
        ]);
        $quotation = PurchaseOrder::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $validated['quotationRequestPublicId'])
            ->where('stage', ProcurementStage::Quotation)
            ->where('status', PurchaseOrderStatus::Draft)
            ->whereNotNull('quotation_requested_at')
            ->with('lines')
            ->first();

        if (! $quotation instanceof PurchaseOrder) {
            $this->addError('quotationRequestPublicId', __('production_bench.procurement.quotation_unavailable'));

            return;
        }

        $order = $convertQuotation->handle($this->user(), $quotation);

        session()->flash('status', __('production_bench.procurement.converted'));
        $this->redirectRoute('production-bench.purchasing.procurement.show', ['purchaseOrder' => $order], navigate: true);
    }

    public function save(CreatePurchaseOrder $createPurchaseOrder): void
    {
        $workspace = $this->workspace();
        $validated = $this->validate([
            'supplierId' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('workspace_id', $workspace->id),
            ],
            'expectedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $supplier = Supplier::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($validated['supplierId']);
        $selectedPacks = collect($this->packs)
            ->filter(fn (mixed $packs): bool => is_numeric($packs) && (int) $packs > 0)
            ->map(fn (mixed $packs): int => (int) $packs);

        if ($selectedPacks->isEmpty()) {
            $this->addError('packs', 'Choose at least one supplier listing.');

            return;
        }

        $listings = SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->where('supplier_id', $supplier->id)
            ->whereIn('id', $selectedPacks->keys())
            ->get()
            ->keyBy('id');

        if ($listings->count() !== $selectedPacks->count()) {
            $this->addError('packs', 'Every selected listing must belong to this supplier.');

            return;
        }

        if ($listings->pluck('currency')->map('strtoupper')->unique()->count() !== 1) {
            $this->addError('packs', __('production_bench.procurement.mixed_currencies'));

            return;
        }

        $order = $createPurchaseOrder->handle(
            actor: $this->user(),
            workspace: $workspace,
            supplier: $supplier,
            lines: $selectedPacks
                ->map(fn (int $packs, int $listingId): array => [
                    'listing' => $listings->get($listingId),
                    'packs' => $packs,
                ])
                ->values()
                ->all(),
            expectedAt: $this->expectedAt,
            notes: $this->notes,
            stage: ProcurementStage::from($this->stage),
        );

        session()->flash('status', ProcurementStage::from($this->stage) === ProcurementStage::Quotation
            ? 'Quotation draft created.'
            : 'Purchase order draft created.');
        $this->redirectRoute('production-bench.purchasing.procurement.show', ['purchaseOrder' => $order], navigate: true);
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $suppliers = Supplier::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $listings = $this->supplierId === null
            ? collect()
            : SupplierListing::query()
                ->where('workspace_id', $workspace->id)
                ->where('supplier_id', $this->supplierId)
                ->where('is_active', true)
                ->with(['ingredient', 'packagingItem'])
                ->orderBy('purchase_format')
                ->get();
        $quotationRequests = ProcurementStage::from($this->stage) === ProcurementStage::Quotation
            ? collect()
            : PurchaseOrder::query()
                ->where('workspace_id', $workspace->id)
                ->where('stage', ProcurementStage::Quotation)
                ->where('status', PurchaseOrderStatus::Draft)
                ->whereNotNull('quotation_requested_at')
                ->with(['supplier', 'lines'])
                ->latest('quotation_requested_at')
                ->get();

        return view('livewire.production-bench.purchasing.procurement-create', [
            'isQuotation' => ProcurementStage::from($this->stage) === ProcurementStage::Quotation,
            'listings' => $listings,
            'suppliers' => $suppliers,
            'quotationRequests' => $quotationRequests,
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
