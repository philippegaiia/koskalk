<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPricePresentation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierDetail extends Component
{
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    public string|Supplier $supplier;

    public string $listingStatus = 'active';

    public int $perPage = 25;

    public function mount(string|Supplier $supplier): void
    {
        $supplierId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $this->supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $supplierId)
            ->firstOrFail();
    }

    public function updatedListingStatus(): void
    {
        $this->resetPage('supplier-listings');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage('supplier-listings');
    }

    public function render(
        ProductionBenchAccess $access,
        SupplierListingPricePresentation $pricePresentation,
    ): View {
        $workspace = $this->workspace();

        return view('livewire.production-bench.purchasing.supplier-detail', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'listingRows' => $this->supplier->listings()
                ->with(['ingredient.translations', 'packagingItem'])
                ->when($this->listingStatus === 'active', fn (Builder $query) => $query->where('is_active', true))
                ->when($this->listingStatus === 'inactive', fn (Builder $query) => $query->where('is_active', false))
                ->latest('id')
                ->paginate($this->normalizedPerPage(), ['*'], 'supplier-listings')
                ->through(fn (SupplierListing $listing): array => [
                    'listing' => $listing,
                    'price' => $pricePresentation->present($listing, $workspace),
                ]),
        ]);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
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
