<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ReceiptIndex extends Component
{
    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.purchasing.receipt-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'receipts' => GoodsReceipt::query()
                ->where('workspace_id', $workspace->id)
                ->with(['supplier', 'purchaseOrder'])
                ->withCount('lines')
                ->latest('received_at')
                ->latest('id')
                ->get(),
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
