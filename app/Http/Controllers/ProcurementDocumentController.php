<?php

namespace App\Http\Controllers;

use App\Enums\ProcurementStage;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;

class ProcurementDocumentController extends Controller
{
    public function show(PurchaseOrder $purchaseOrder): View
    {
        $workspace = auth()->user()?->company() ?? abort(404);

        abort_unless($purchaseOrder->workspace_id === $workspace->id, 404);

        $isQuotation = $purchaseOrder->stage === ProcurementStage::Quotation
            || ($purchaseOrder->purchase_order_snapshot === null && $purchaseOrder->quotation_snapshot !== null);
        $snapshot = $isQuotation
            ? $purchaseOrder->quotation_snapshot
            : $purchaseOrder->purchase_order_snapshot;

        abort_if($snapshot === null, 404);

        return view('production-bench.purchasing.document-print', [
            'isQuotation' => $isQuotation,
            'order' => $purchaseOrder,
            'snapshot' => $snapshot,
        ]);
    }
}
