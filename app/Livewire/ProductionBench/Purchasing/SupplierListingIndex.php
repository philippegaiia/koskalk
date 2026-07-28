<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierListingIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $supplierId = null;

    public string $materialType = 'all';

    public string $status = 'active';

    public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierId(): void
    {
        $this->resetPage();
    }

    public function updatedMaterialType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $search = trim($this->search);
        $searchTerm = '%'.Str::lower($search).'%';
        $listings = SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->with(['supplier', 'ingredient.translations', 'packagingItem'])
            ->when($this->supplierId, fn ($query) => $query->where('supplier_id', $this->supplierId))
            ->when($this->materialType === 'ingredient', fn ($query) => $query->whereNotNull('ingredient_id'))
            ->when($this->materialType === 'packaging', fn ($query) => $query->whereNotNull('user_packaging_item_id'))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', function ($query) use ($searchTerm): void {
                $query->where(function ($searchQuery) use ($searchTerm): void {
                    $searchQuery
                        ->whereRaw('LOWER(supplier_sku) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_name) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(purchase_format) LIKE ?', [$searchTerm])
                        ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('ingredient', fn ($ingredientQuery) => $ingredientQuery->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('packagingItem', fn ($packagingQuery) => $packagingQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]));
                });
            })
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.production-bench.purchasing.supplier-listing-index', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'listings' => $listings,
            'suppliers' => Supplier::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
            'workspace' => $workspace,
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
