<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\AttachProductionDocument;
use App\Enums\ProductionDocumentType;
use App\Models\GoodsReceipt;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachGoodsReceiptDocuments
{
    /** @var array<int, ProductionDocumentType> */
    private const RECEIPT_TYPES = [
        ProductionDocumentType::Invoice,
        ProductionDocumentType::Receipt,
        ProductionDocumentType::DeliveryNote,
        ProductionDocumentType::Photo,
        ProductionDocumentType::Other,
    ];

    /** @var array<int, ProductionDocumentType> */
    private const LOT_TYPES = [
        ProductionDocumentType::CertificateOfAnalysis,
        ProductionDocumentType::SafetyDataSheet,
        ProductionDocumentType::Specification,
        ProductionDocumentType::Certificate,
    ];

    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly AttachProductionDocument $attach,
    ) {}

    /**
     * @param  array<int, int>  $selectedLotIds
     * @return EloquentCollection<int, ProductionDocument>
     */
    public function handle(
        User $actor,
        GoodsReceipt $receipt,
        MediaAsset $asset,
        ProductionDocumentType $type,
        array $selectedLotIds = [],
        ?string $note = null,
    ): EloquentCollection {
        $lots = $this->validateTargets($actor, $receipt, $type, $selectedLotIds);
        $workspace = Workspace::withoutGlobalScopes()->find($receipt->workspace_id);

        if (! $workspace instanceof Workspace || (int) $asset->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages([
                'document' => __('production_bench.receipt.document_workspace_mismatch'),
            ]);
        }

        if (in_array($type, self::RECEIPT_TYPES, true)) {
            return new EloquentCollection([$this->attach->handle($actor, $receipt, $asset, $type, $note)]);
        }

        return DB::transaction(fn (): EloquentCollection => $lots->map(
            fn (StockLot $lot): ProductionDocument => $this->attach->handle($actor, $lot, $asset, $type, $note),
        ));
    }

    /**
     * @param  array<int, int>  $selectedLotIds
     * @return EloquentCollection<int, StockLot>
     */
    public function validateTargets(
        User $actor,
        GoodsReceipt $receipt,
        ProductionDocumentType $type,
        array $selectedLotIds = [],
    ): EloquentCollection {
        $workspace = Workspace::withoutGlobalScopes()->find($receipt->workspace_id);

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'document' => __('production_bench.receipt.document_workspace_mismatch'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);
        $selectedLotIds = array_values(array_unique(array_map('intval', $selectedLotIds)));

        if (in_array($type, self::RECEIPT_TYPES, true)) {
            if ($selectedLotIds !== []) {
                throw ValidationException::withMessages([
                    'documentLotIds' => __('production_bench.receipt.document_receipt_target_only'),
                ]);
            }

            return new EloquentCollection;
        }

        if (! in_array($type, self::LOT_TYPES, true)) {
            throw ValidationException::withMessages([
                'documentType' => __('production_bench.receipt.document_type_invalid'),
            ]);
        }

        if ($selectedLotIds === []) {
            throw ValidationException::withMessages([
                'documentLotIds' => __('production_bench.receipt.document_lot_target_required'),
            ]);
        }

        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $selectedLotIds)
            ->whereHas('goodsReceiptLine', fn ($query) => $query->where('goods_receipt_id', $receipt->id))
            ->get();

        if ($lots->count() !== count($selectedLotIds)) {
            throw ValidationException::withMessages([
                'documentLotIds' => __('production_bench.receipt.document_lot_target_invalid'),
            ]);
        }

        return $lots;
    }
}
