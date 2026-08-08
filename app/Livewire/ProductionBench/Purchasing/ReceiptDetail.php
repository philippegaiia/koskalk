<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\AttachGoodsReceiptDocuments;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\MediaAssetType;
use App\Enums\ProductionDocumentType;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaAssetUploadService;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class ReceiptDetail extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $receiptPublicId;

    public string $reversalReason = '';

    public $documentUpload;

    public string $documentType = ProductionDocumentType::Invoice->value;

    /** @var array<int, int> */
    public array $documentLotIds = [];

    public string $documentNote = '';

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

    public function updatedDocumentType(): void
    {
        if (in_array($this->documentType, $this->receiptDocumentTypeValues(), true)) {
            $this->documentLotIds = [];
        }
    }

    public function attachDocument(
        MediaAssetUploadService $uploads,
        AttachGoodsReceiptDocuments $attach,
    ): void {
        $receiptTypes = $this->receiptDocumentTypeValues();
        $lotTypes = $this->lotDocumentTypeValues();
        $validated = $this->validate([
            'documentUpload' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:10240'],
            'documentType' => ['required', Rule::in([...$receiptTypes, ...$lotTypes])],
            'documentLotIds' => [Rule::requiredIf(in_array($this->documentType, $lotTypes, true)), 'array'],
            'documentLotIds.*' => ['integer'],
            'documentNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $type = ProductionDocumentType::from($validated['documentType']);
        $selectedLotIds = $validated['documentLotIds'] ?? [];
        $attach->validateTargets($this->user(), $this->receipt(), $type, $selectedLotIds);
        $asset = null;

        try {
            $asset = $uploads->start(
                $this->user(),
                $this->workspace(),
                $validated['documentUpload'],
                [MediaAssetType::Image, MediaAssetType::Pdf],
                processSynchronously: true,
            )->refresh();

            $attach->handle(
                actor: $this->user(),
                receipt: $this->receipt(),
                asset: $asset,
                type: $type,
                selectedLotIds: $selectedLotIds,
                note: filled($validated['documentNote'] ?? null) ? trim($validated['documentNote']) : null,
            );
        } catch (Throwable $exception) {
            if ($asset !== null && $asset->exists) {
                try {
                    $uploads->rollbackUnreferencedUpload($this->user(), $this->workspace(), $asset);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if (! $exception instanceof ValidationException) {
                throw $exception;
            }

            $errors = $exception->errors();

            if (isset($errors['upload']) || isset($errors['document'])) {
                throw ValidationException::withMessages([
                    'documentUpload' => collect($errors['upload'] ?? $errors['document'])->first(),
                ]);
            }

            throw $exception;
        }

        $this->reset('documentUpload', 'documentLotIds', 'documentNote');
        $this->documentType = ProductionDocumentType::Invoice->value;
        session()->flash('documentStatus', __('production_bench.receipt.document_attached'));
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
            'canAttachDocuments' => ! $access->isReadOnly($this->workspace()),
            'receiptDocumentTypes' => $this->receiptDocumentTypeValues(),
            'lotDocumentTypes' => $this->lotDocumentTypeValues(),
        ]);
    }

    /** @return array<int, string> */
    private function receiptDocumentTypeValues(): array
    {
        return [
            ProductionDocumentType::Invoice->value,
            ProductionDocumentType::Receipt->value,
            ProductionDocumentType::DeliveryNote->value,
            ProductionDocumentType::Photo->value,
            ProductionDocumentType::Other->value,
        ];
    }

    /** @return array<int, string> */
    private function lotDocumentTypeValues(): array
    {
        return [
            ProductionDocumentType::CertificateOfAnalysis->value,
            ProductionDocumentType::SafetyDataSheet->value,
            ProductionDocumentType::Specification->value,
            ProductionDocumentType::Certificate->value,
        ];
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
