<?php

namespace App\Livewire\ProductionBench;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockLotStatus;
use App\Models\PurchaseOrder;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class HomeIndex extends Component
{
    public function activate(ProductionBenchAccess $access): void
    {
        $access->activate($this->user(), $this->workspace());
    }

    public function cancel(ProductionBenchAccess $access): void
    {
        $access->cancel($this->user(), $this->workspace());
    }

    public function resume(ProductionBenchAccess $access): void
    {
        $access->resume($this->user(), $this->workspace());
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.home-index', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'quarantinedLots' => StockLot::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', StockLotStatus::Quarantined)
                ->count(),
            'incomingOrders' => PurchaseOrder::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived])
                ->count(),
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
