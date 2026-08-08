<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Enums\ProcurementStage;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProcurementIndex extends Component
{
    #[Locked]
    public string $stage;

    public function mount(string $stage): void
    {
        $this->stage = ProcurementStage::from($stage)->value;
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $orders = PurchaseOrder::query()
            ->where('workspace_id', $workspace->id)
            ->where('stage', $this->stage)
            ->with('supplier')
            ->latest('id')
            ->get();

        return view('livewire.production-bench.purchasing.procurement-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'isQuotation' => ProcurementStage::from($this->stage) === ProcurementStage::Quotation,
            'orders' => $orders,
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
