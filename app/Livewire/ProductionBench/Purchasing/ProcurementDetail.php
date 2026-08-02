<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\ConvertQuotationToPurchaseOrder;
use App\Actions\Purchasing\IssueQuotationRequest;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\RecordProcurementLinePrice;
use App\ListingPriceBasis;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Workspace;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use App\Services\ProcurementDocumentFormatter;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProcurementDetail extends Component
{
    #[Locked]
    public string $orderPublicId;

    /** @var array<int, array{basis: string, amount: string|null, unit: string|null}> */
    public array $priceInputs = [];

    /** @var array<string, string|null> */
    public array $deliveryAddress = ['name' => null, 'city' => null, 'country_code' => null];

    public string $shippingAmount = '0';

    public string $discountAmount = '0';

    public string $taxAmount = '0';

    public function updatedPriceInputs(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$lineId, $field] = array_pad(explode('.', $key, 2), 2, null);

        if (! is_numeric($lineId) || $field !== 'basis') {
            return;
        }

        $lineId = (int) $lineId;

        if ((string) $value === ListingPriceBasis::TotalPurchaseFormat->value) {
            $this->priceInputs[$lineId]['unit'] = null;

            return;
        }

        if ((string) $value === ListingPriceBasis::PerUnit->value && blank($this->priceInputs[$lineId]['unit'] ?? null)) {
            $line = $this->order()->lines()->with('supplierListing')->find($lineId);

            if ($line instanceof PurchaseOrderLine) {
                $this->priceInputs[$lineId]['unit'] = $line->supplierListing?->net_unit
                    ?? ($line->unit_kind->value === 'mass' ? $this->workspace()->mass_display_system->priceUnit()->value : 'count');
            }
        }
    }

    public function mount(ProductionBenchAccess $access, string|PurchaseOrder $purchaseOrder): void
    {
        $order = $purchaseOrder instanceof PurchaseOrder
            ? $this->workspaceOrder($purchaseOrder->public_id)
            : $this->workspaceOrder($purchaseOrder);
        $this->orderPublicId = $order->public_id;
        $this->deliveryAddress['name'] = $this->workspace()->name;

        foreach ($order->lines as $line) {
            $defaultUnit = $line->supplierListing?->net_unit
                ?? ($line->unit_kind->value === 'mass' ? $this->workspace()->mass_display_system->priceUnit()->value : 'count');

            $this->priceInputs[$line->id] = [
                'basis' => $line->price_basis?->value ?? ListingPriceBasis::PerUnit->value,
                'amount' => $line->price_amount === null
                    ? null
                    : NumberLocale::formatDecimal((float) $line->price_amount, 2),
                'unit' => $line->price_basis === ListingPriceBasis::TotalPurchaseFormat
                    ? null
                    : ($line->price_unit ?? $defaultUnit),
            ];
        }

        abort_unless($access->isActive($this->workspace()) || $access->isReadOnly($this->workspace()), 404);
    }

    public function issueQuotation(IssueQuotationRequest $issueQuotationRequest): void
    {
        $issueQuotationRequest->handle($this->user(), $this->order());
        session()->flash('status', 'Quotation request issued.');
    }

    public function recordPrice(int $lineId, RecordProcurementLinePrice $recordProcurementLinePrice): void
    {
        $order = $this->order();
        $line = $order->lines()->findOrFail($lineId);
        $parsedAmount = NumberLocale::parseDecimalInput($this->priceInputs[$lineId]['amount'] ?? null);

        if ($parsedAmount !== null) {
            $this->priceInputs[$lineId]['amount'] = (string) $parsedAmount;
        }

        $data = $this->validate([
            "priceInputs.{$lineId}.basis" => ['required', Rule::enum(ListingPriceBasis::class)],
            "priceInputs.{$lineId}.amount" => ['required', 'numeric', 'gt:0'],
            "priceInputs.{$lineId}.unit" => ['nullable', 'string', 'max:24'],
        ]);
        $input = $data['priceInputs'][$lineId];
        $basis = ListingPriceBasis::from($input['basis']);

        $recordProcurementLinePrice->handle(
            actor: $this->user(),
            line: $line,
            basis: $basis,
            amount: (string) $input['amount'],
            unit: $basis === ListingPriceBasis::TotalPurchaseFormat ? null : ($input['unit'] ?? null),
        );
        session()->flash('status', 'Price recorded.');
    }

    public function convertToPurchaseOrder(ConvertQuotationToPurchaseOrder $convertQuotation): void
    {
        $convertQuotation->handle($this->user(), $this->order());
        session()->flash('status', 'Converted to purchase order.');
    }

    public function issuePurchaseOrder(PlacePurchaseOrder $placePurchaseOrder): void
    {
        $data = $this->validate([
            'deliveryAddress.name' => ['nullable', 'string', 'max:255'],
            'deliveryAddress.city' => ['nullable', 'string', 'max:255'],
            'deliveryAddress.country_code' => ['nullable', 'string', 'max:2'],
            'shippingAmount' => ['required', 'numeric', 'min:0'],
            'discountAmount' => ['required', 'numeric', 'min:0'],
            'taxAmount' => ['required', 'numeric', 'min:0'],
        ]);

        $placePurchaseOrder->handle(
            actor: $this->user(),
            order: $this->order(),
            deliveryAddress: array_filter($data['deliveryAddress'], fn (?string $value): bool => filled($value)),
            shippingAmount: (string) $data['shippingAmount'],
            discountAmount: (string) $data['discountAmount'],
            taxAmount: (string) $data['taxAmount'],
        );
        session()->flash('status', 'Purchase order issued.');
    }

    public function render(ProductionBenchAccess $access, ProcurementDocumentFormatter $formatter): View
    {
        $order = $this->order()->load(['supplier', 'lines.ingredient', 'lines.packagingItem']);
        $hasIssuedDocument = $order->quotation_snapshot !== null || $order->purchase_order_snapshot !== null;

        return view('livewire.production-bench.purchasing.procurement-detail', [
            'emailText' => $hasIssuedDocument ? $formatter->emailText($order) : null,
            'isReadOnly' => $access->isReadOnly($this->workspace()),
            'order' => $order,
            'isQuotation' => $order->stage === ProcurementStage::Quotation,
            'canIssueQuotation' => $order->stage === ProcurementStage::Quotation && $order->quotation_requested_at === null,
            'canPrice' => $order->issued_at === null && (
                ($order->stage === ProcurementStage::Quotation && $order->quotation_requested_at !== null)
                || ($order->stage === ProcurementStage::PurchaseOrder && $order->status === PurchaseOrderStatus::Draft)
            ),
            'canConvert' => $order->stage === ProcurementStage::Quotation
                && $order->quotation_requested_at !== null,
            'canIssueOrder' => $order->stage === ProcurementStage::PurchaseOrder
                && $order->status === PurchaseOrderStatus::Draft
                && $order->lines->every(fn (PurchaseOrderLine $line): bool => $line->pack_price !== null),
            'needsPrices' => $order->lines->contains(fn (PurchaseOrderLine $line): bool => $line->pack_price === null),
        ]);
    }

    private function order(): PurchaseOrder
    {
        return $this->workspaceOrder($this->orderPublicId);
    }

    private function workspaceOrder(string $publicId): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $publicId)
            ->with('lines.supplierListing')
            ->firstOrFail();
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
