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
use Livewire\WithPagination;

class ProcurementIndex extends Component
{
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    #[Locked]
    public string $stage;

    public int $perPage = 25;

    public function mount(string $stage): void
    {
        $this->stage = ProcurementStage::from($stage)->value;
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $orders = PurchaseOrder::query()
            ->where('workspace_id', $workspace->id)
            ->where('stage', $this->stage)
            ->with('supplier')
            ->latest('id')
            ->paginate($this->normalizedPerPage());

        return view('livewire.production-bench.purchasing.procurement-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'isQuotation' => ProcurementStage::from($this->stage) === ProcurementStage::Quotation,
            'orders' => $orders,
        ]);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true)
            ? $this->perPage
            : 25;
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
