<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReceiptDetail extends Component
{
    #[Locked]
    public string $receiptPublicId;

    public string $reversalReason = '';

    public function mount(ProductionBenchAccess $access, string|GoodsReceipt $goodsReceipt): void
    {
        $publicId = $goodsReceipt instanceof GoodsReceipt ? $goodsReceipt->public_id : $goodsReceipt;
        $this->receiptPublicId = $this->workspaceReceipt($publicId)->public_id;
        abort_unless($access->isActive($this->workspace()) || $access->isReadOnly($this->workspace()), 404);
    }

    public function reverse(ReverseGoodsReceipt $action): void
    {
        $this->reversalReason = trim($this->reversalReason);
        $validated = $this->validate([
            'reversalReason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($this->user(), $this->receipt(), $validated['reversalReason']);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['reversalReason'])) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'reversalReason' => collect($exception->errors())->flatten()->first()
                    ?? __('production_bench.receipt.reversal_reason_invalid'),
            ]);
        }
        session()->flash('status', __('production_bench.receipt.reversed'));
    }

    public function render(ProductionBenchAccess $access): View
    {
        $receipt = $this->receipt()->load([
            'supplier',
            'purchaseOrder',
            'lines.supplierListing',
            'lines.purchaseOrderLine',
            'lines.stockLot.ingredient.translations',
            'lines.stockLot.packagingItem',
            'documents.mediaAsset',
            'lines.stockLot.documents.mediaAsset',
        ]);

        return view('livewire.production-bench.purchasing.receipt-detail', [
            'receipt' => $receipt,
            'isReadOnly' => $access->isReadOnly($this->workspace()),
            'canReverse' => ! $access->isReadOnly($this->workspace())
                && $receipt->status === GoodsReceiptStatus::Posted,
        ]);
    }

    private function receipt(): GoodsReceipt
    {
        return $this->workspaceReceipt($this->receiptPublicId);
    }

    private function workspaceReceipt(string $publicId): GoodsReceipt
    {
        return GoodsReceipt::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $publicId)
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
